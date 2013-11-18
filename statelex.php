<?php
class RestartException extends RuntimeException {}
class LexingException  extends RuntimeException {}
class LexerDataGenerator {
	public function getCompiledRegex(array $regexes, $additionalModifiers = '') {
		return '~(' . str_replace('~', '\~', implode(')|(', $regexes)) . ')~A' . $additionalModifiers;
	}

	public function getCompiledRegexForPregReplace(array $regexes, $additionalModifiers = '') {
		// the \G is not strictly necessary, but it makes preg_replace abort early when not lexable
		return '~\G((' . str_replace('~', '\~', implode(')|(', $regexes)) . '))~' . $additionalModifiers;
	}

	public function getOffsetToLengthMap(array $regexes) {
		$offsetToLengthMap = array();

		$currentOffset = 0;
		foreach ($regexes as $regex) {
			// We have to add +1 because the whole regex will also be made capturing
			$numberOfCapturingGroups = 1 + $this->getNumberOfCapturingGroupsInRegex($regex);

			$offsetToLengthMap[$currentOffset] = $numberOfCapturingGroups;
			$currentOffset += $numberOfCapturingGroups;
		}

		return $offsetToLengthMap;
	}

	protected function getNumberOfCapturingGroupsInRegex($regex) {
		// The regex to count the number of capturing groups should be fairly complete. The only thing I know it
		// won't work with are (?| ... ) groups.
		return preg_match_all(
			'~
				(?:
					\(\?\(
				  | \[ [^\]\\\\]* (?: \\\\ . [^\]\\\\]* )* \]
				  | \\\\ .
				) (*SKIP)(*FAIL) |
				\(
				(?!
					\?
					(?!
						<(?![!=])
					  | P<
					  | \'
					)
				  | \*
				)
			~x',
			$regex, $dummyVar
		);
	}
}


class UsingCompiledRegexFactory {
	protected $dataGen;

	public function __construct(LexerDataGenerator $dataGen) {
		$this->dataGen = $dataGen;
	}

	public function createLexer(array $lexerDefinition, $additionalModifiers = '') {
		$initialState = key($lexerDefinition);

		$stateData = array();
		foreach ($lexerDefinition as $state => $regexToActionMap) {
			$regexes = array_keys($regexToActionMap);

			$compiledRegex = $this->dataGen->getCompiledRegex($regexes, $additionalModifiers);
			$offsetToLengthMap = $this->dataGen->getOffsetToLengthMap($regexes);
			$offsetToActionMap = array_combine(array_keys($offsetToLengthMap), $regexToActionMap);

			$stateData[$state] = array(
				'compiledRegex'     => $compiledRegex,
				'offsetToActionMap' => $offsetToActionMap,
				'offsetToLengthMap' => $offsetToLengthMap,
			);
		}

		return new Stateful($initialState, $stateData);
	}
}

class Stateful {
	protected $initialState;

	/* arrays with indices compiledRegex, offsetToActionMap, offsetToLengthMap */
	protected $stateData;

	protected $stateStack;
	protected $currentStackPosition;
	protected $currentStateData;

	public function __construct($initialState, array $stateData) {
		$this->initialState = $initialState;
		$this->stateData = $stateData;
	}

	public function pushState($state) {
		$this->stateStack[++$this->currentStackPosition] = $state;
		$this->currentStateData = $this->stateData[$state];
	}

	public function popState() {
		$state = $this->stateStack[--$this->currentStackPosition];
		$this->currentStateData = $this->stateData[$state];
	}

	public function swapState($state) {
		$this->stateStack[$this->currentStackPosition] = $state;
		$this->currentStateData = $this->stateData[$state];
	}

	public function hasPushedStates() {
		return $this->currentStackPosition > 0;
	}

	public function getStateStack() {
		return array_slice($this->stateStack, 0, $this->currentStackPosition + 1);
	}

	public function lex($string) {
		$tokens = array();

		$this->stateStack = array($this->initialState);
		$this->currentStackPosition = 0;
		$this->currentStateData = $this->stateData[$this->initialState];

		$offset = 0;
		$line = 1;
		while (isset($string[$offset])) {
			if (!preg_match($this->currentStateData['compiledRegex'], $string, $matches, 0, $offset)) {
				throw new LexingException(sprintf(
					'Unexpected character "%s" on line %d', $string[$offset], $line
				));
			}

			// find the first non-empty element (but skipping $matches[0]) using a quick for loop
			for ($i = 1; '' === $matches[$i]; ++$i);

			$action = $this->currentStateData['offsetToActionMap'][$i - 1];
			if (is_callable($action)) {
				$realMatches = array();
				for ($j = 0; $j < $this->currentStateData['offsetToLengthMap'][$i - 1]; ++$j) {
					if (isset($matches[$i + $j])) {
						$realMatches[$j] = $matches[$i + $j];
					}
				}

				try {
					$token = array($action($this, $realMatches), $line, $matches[0]);
				} catch (RestartException $e) {
					continue;
				}
			} else {
				$token = array($action, $line, $matches[0]);
			}

			$tokens[] = $token;

			$offset += strlen($matches[0]);
			$line += substr_count($matches[0], "\n");
		}

		return $tokens;
	}
}

$grpSpace = '[ \t\n\r]';
$grpAlpha = '[a-zA-Z]';
$grpLiteralId = '[a-zA-Z]';
$grpLiteralMn = '[0-9]';
$grpLiteralUfLt = '[,:;\?\!\']';
$grpDelimiterUfLt = '[\(\)\.]';
$grpLiteralUfOp = '[+-*=]';
$grpDelimiterUfOp = '[/|]';
$grpBoxchars  = '[0-9a-zA-Z\+-\*,=\(\):/;?.!\\` \128-\255]';
$grpAboxchars = '[0-9a-zA-Z\+-*,=\(\):/;?.!\\` ]';
$textIdentifier = '(text|[hmv]box)';


$lexerDefinition = array(
	'MATHMODE' => array(
		'\\\\[\\\\, ;!\{\}\|#%\$&_^]' => function(Stateful $lexer) {
			return 'escapedChar';
		},
		'\\\\' => function(Stateful $lexer) {
			$lexer->swapState('COMMANDNAME');
			return '\\';
		},
		'[_\^]' => function(Stateful $lexer) {
			//$lexer->swapState('SUBSUP');
			return '_^';
		},
		'\s' => 'WHITESPACE',
		'%' => 'MWESCAPE',
		'[0-9a-zA-Z\+\(\)\s\=\-\,\*\:/\.\;\?!\`´\[\]\>\<\|\'~&]'=> 'MATHCHAR',
		'\{' => function(Stateful $lexer) {
			$lexer->pushState('MATHMODE');
			return '{';
		},
		'\}' => function(Stateful $lexer) {
			if ($lexer->hasPushedStates()) {
				$lexer->popState();
				return '}';
			} else {
				return 'err_}';
			}
		},

	),
	'COMMANDNAME' => array(
		$textIdentifier.'\s+' => function(Stateful $lexer) {
				$lexer->swapState('TEXTMODE');
				return 'TEXTCOMMANDNAME';
			},
		$textIdentifier.'{' => function(Stateful $lexer) {
				$lexer->swapState('MATHMODE');
				$lexer->pushState('LONGTEXTMODE');
				return 'TEXTCOMMANDNAME';
			},
		'[a-zA-Z]+' => function(Stateful $lexer) {
				$lexer->swapState('MATHMODE');
				return 'COMMANDNAME';
			}
	),
	'TEXTMODE' => array(
		'(\\\\[\{\}]|[^\{\}])' => function(Stateful $lexer){
			$lexer->swapState('MATHMODE');
			return 'TEXT';
			},
		'\{' => function(Stateful $lexer) {
			$lexer->swapState('MATHMODE');
			$lexer->pushState('LONGTEXTMODE');
			return 'TEXT';
			},
		'\}' => 'err_}',
		),
	'LONGTEXTMODE' => array(
		'(\\\\[\{\}]|[^\{\}])+' => 'TEXT',
		'\{' => function(Stateful $lexer) {
			$lexer->pushState('TEXTMODE');
			return 'TEXT';
			},
		'\}' => function(Stateful $lexer) {
				if ($lexer->hasPushedStates()) {
					$lexer->popState();
					return 'TEXT-}';
				} else {
					return 'err_}';
				}
			},
		),
	);

class CheckTex{
/** @var array whitelist of allowed tex literals **/
	private $texLiterals = array('AA', 'aleph', 'alpha', 'amalg', 'And', 'angle', 'approx', 'approxeq', 'ast', 'asymp', 'backepsilon', 'backprime', 'backsim', 'backsimeq', 'barwedge', 'Bbbk', 'because', 'beta', 'beth', 'between', 'bigcap', 'bigcirc', 'bigcup', 'bigodot', 'bigoplus', 'bigotimes', 'bigsqcup', 'bigstar', 'bigtriangledown', 'bigtriangleup', 'biguplus', 'bigvee', 'bigwedge', 'blacklozenge', 'blacksquare', 'blacktriangle', 'blacktriangledown', 'blacktriangleleft', 'blacktriangleright', 'bot', 'bowtie', 'Box', 'boxdot', 'boxminus', 'boxplus', 'boxtimes', 'bullet', 'bumpeq', 'Bumpeq', 'cap', 'Cap', 'cdot', 'cdots', 'centerdot', 'checkmark', 'chi', 'circ', 'circeq', 'circlearrowleft', 'circlearrowright', 'circledast', 'circledcirc', 'circleddash', 'circledS', 'clubsuit', 'colon', 'color', 'complement', 'cong', 'coprod', 'cup', 'Cup', 'curlyeqprec', 'curlyeqsucc', 'curlyvee', 'curlywedge', 'curvearrowleft', 'curvearrowright', 'dagger', 'daleth', 'dashv', 'ddagger', 'ddots', 'definecolor', 'delta', 'Delta', 'diagdown', 'diagup', 'diamond', 'Diamond', 'diamondsuit', 'digamma', 'displaystyle', 'div', 'divideontimes', 'doteq', 'doteqdot', 'dotplus', 'dots', 'dotsb', 'dotsc', 'dotsi', 'dotsm', 'dotso', 'doublebarwedge', 'downdownarrows', 'downharpoonleft', 'downharpoonright', 'ell', 'emptyset', 'epsilon', 'eqcirc', 'eqsim', 'eqslantgtr', 'eqslantless', 'equiv', 'eta', 'eth', 'exists', 'fallingdotseq', 'Finv', 'flat', 'forall', 'frown', 'Game', 'gamma', 'Gamma', 'geq', 'geqq', 'geqslant', 'gets', 'gg', 'ggg', 'gimel', 'gnapprox', 'gneq', 'gneqq', 'gnsim', 'gtrapprox', 'gtrdot', 'gtreqless', 'gtreqqless', 'gtrless', 'gtrsim', 'gvertneqq', 'hbar', 'heartsuit', 'hline', 'hookleftarrow', 'hookrightarrow', 'hslash', 'iff', 'iiiint', 'iiint', 'iint', 'Im', 'imath', 'implies', 'in', 'infty', 'injlim', 'int', 'intercal', 'iota', 'jmath', 'kappa', 'lambda', 'Lambda', 'land', 'ldots', 'leftarrow', 'Leftarrow', 'leftarrowtail', 'leftharpoondown', 'leftharpoonup', 'leftleftarrows', 'leftrightarrow', 'Leftrightarrow', 'leftrightarrows', 'leftrightharpoons', 'leftrightsquigarrow', 'leftthreetimes', 'leq', 'leqq', 'leqslant', 'lessapprox', 'lessdot', 'lesseqgtr', 'lesseqqgtr', 'lessgtr', 'lesssim', 'limits', 'll', 'Lleftarrow', 'lll', 'lnapprox', 'lneq', 'lneqq', 'lnot', 'lnsim', 'longleftarrow', 'Longleftarrow', 'longleftrightarrow', 'Longleftrightarrow', 'longmapsto', 'longrightarrow', 'Longrightarrow', 'looparrowleft', 'looparrowright', 'lor', 'lozenge', 'Lsh', 'ltimes', 'lVert', 'lvertneqq', 'mapsto', 'measuredangle', 'mho', 'mid', 'mod', 'models', 'mp', 'mu', 'multimap', 'nabla', 'natural', 'ncong', 'nearrow', 'neg', 'neq', 'nexists', 'ngeq', 'ngeqq', 'ngeqslant', 'ngtr', 'ni', 'nleftarrow', 'nLeftarrow', 'nleftrightarrow', 'nLeftrightarrow', 'nleq', 'nleqq', 'nleqslant', 'nless', 'nmid', 'nolimits', 'not', 'notin', 'nparallel', 'nprec', 'npreceq', 'nrightarrow', 'nRightarrow', 'nshortmid', 'nshortparallel', 'nsim', 'nsubseteq', 'nsubseteqq', 'nsucc', 'nsucceq', 'nsupseteq', 'nsupseteqq', 'ntriangleleft', 'ntrianglelefteq', 'ntriangleright', 'ntrianglerighteq', 'nu', 'nvdash', 'nVdash', 'nvDash', 'nVDash', 'nwarrow', 'odot', 'oint', 'omega', 'Omega', 'ominus', 'oplus', 'oslash', 'otimes', 'overbrace', 'overleftarrow', 'overleftrightarrow', 'overline', 'overrightarrow', 'P', 'pagecolor', 'parallel', 'partial', 'perp', 'phi', 'Phi', 'pi', 'Pi', 'pitchfork', 'pm', 'prec', 'precapprox', 'preccurlyeq', 'preceq', 'precnapprox', 'precneqq', 'precnsim', 'precsim', 'prime', 'prod', 'projlim', 'propto', 'psi', 'Psi', 'qquad', 'quad', 'Re', 'rho', 'rightarrow', 'Rightarrow', 'rightarrowtail', 'rightharpoondown', 'rightharpoonup', 'rightleftarrows', 'rightrightarrows', 'rightsquigarrow', 'rightthreetimes', 'risingdotseq', 'Rrightarrow', 'Rsh', 'rtimes', 'rVert', 'S', 'scriptscriptstyle', 'scriptstyle', 'searrow', 'setminus', 'sharp', 'shortmid', 'shortparallel', 'sigma', 'Sigma', 'sim', 'simeq', 'smallfrown', 'smallsetminus', 'smallsmile', 'smile', 'spadesuit', 'sphericalangle', 'sqcap', 'sqcup', 'sqsubset', 'sqsubseteq', 'sqsupset', 'sqsupseteq', 'square', 'star', 'subset', 'Subset', 'subseteq', 'subseteqq', 'subsetneq', 'subsetneqq', 'succ', 'succapprox', 'succcurlyeq', 'succeq', 'succnapprox', 'succneqq', 'succnsim', 'succsim', 'sum', 'supset', 'Supset', 'supseteq', 'supseteqq', 'supsetneq', 'supsetneqq', 'surd', 'swarrow', 'tau', 'textstyle', 'textvisiblespace', 'therefore', 'theta', 'Theta', 'thickapprox', 'thicksim', 'times', 'to', 'top', 'triangle', 'triangledown', 'triangleleft', 'trianglelefteq', 'triangleq', 'triangleright', 'trianglerighteq', 'underbrace', 'underline', 'upharpoonleft', 'upharpoonright', 'uplus', 'upsilon', 'Upsilon', 'upuparrows', 'varepsilon', 'varinjlim', 'varkappa', 'varliminf', 'varlimsup', 'varnothing', 'varphi', 'varpi', 'varprojlim', 'varpropto', 'varrho', 'varsigma', 'varsubsetneq', 'varsubsetneqq', 'varsupsetneq', 'varsupsetneqq', 'vartheta', 'vartriangle', 'vartriangleleft', 'vartriangleright', 'vdash', 'Vdash', 'vDash', 'vdots', 'vee', 'veebar', 'vline', 'Vvdash', 'wedge', 'widehat', 'widetilde', 'wp', 'wr', 'xi', 'Xi', 'zeta');


	private $texBig = array('big', 'Big', 'bigg', 'Bigg', 'biggl', 'Biggl', 'biggr', 'Biggr', 'bigl', 'Bigl', 'bigr', 'Bigr');

	private $texDelimiter = array('backslash', 'downarrow', 'Downarrow', 'langle', 'lbrace', 'lceil', 'lfloor', 'llcorner', 'lrcorner', 'rangle', 'rbrace', 'rceil', 'rfloor', 'rightleftharpoons', 'twoheadleftarrow', 'twoheadrightarrow', 'ulcorner', 'uparrow', 'Uparrow', 'updownarrow', 'Updownarrow', 'urcorner', 'Vert', 'vert', 'lbrack', 'rbrack');

	private $texFunAr1 = array('acute', 'bar', 'bcancel', 'bmod', 'boldsymbol', 'breve', 'cancel', 'check', 'ddot', 'dot', 'emph', 'grave', 'hat', 'mathbb', 'mathbf', 'mathbin', 'mathcal', 'mathclose', 'mathfrak', 'mathit', 'mathop', 'mathopen', 'mathord', 'mathpunct', 'mathrel', 'mathrm', 'mathsf', 'mathtt', /*'operatorname',*/ 'pmod', 'sqrt', 'textbf', 'textit', 'textrm', 'textsf', 'texttt', 'tilde', 'vec', 'xcancel', 'xleftarrow', 'xrightarrow', 'begin' ,'end');

	private $texFunAr2 = array('binom', 'cancelto', 'cfrac', 'dbinom', 'dfrac', 'frac', 'overset', 'stackrel', 'tbinom', 'tfrac', 'underset');

	private $texFunInfix = array('atop', 'choose', 'over');

	private $texBoxChar = array('Coppa', 'coppa', 'Digamma', 'euro', 'geneuro', 'geneuronarrow', 'geneurowide', 'Koppa', 'koppa', 'officialeuro', 'Sampi', 'sampi', 'Stigma', 'stigma', 'varstigma');

	private $mwDelimiter = array('darr' => 'downarrow', 'dArr' => 'Downarrow', 'Darr' => 'Downarrow', 'lang' => 'langle', 'rang' => 'rangle', 'uarr' => 'uparrow', 'uArr' => 'Uparrow', 'Uarr' => 'Uparrow');

	private $mwFunAr1 = array('Bbb' => 'mathbb', 'bold' => 'mathbf');
/**@var array replacement of mediawiki specific function in the form 'custom_command' => 'tex_command'**/
	private $mwLiterals = array('alef' => 'aleph', 'alefsym' => 'aleph', 'Alpha' => 'mathrm{A}', 'and' => 'land', 'ang' => 'angle', 'Beta' => 'mathrm{B}', 'bull' => 'bullet', 'Chi' => 'mathrm{X}', 'clubs' => 'clubsuit', 'cnums' => 'mathbb{C}', 'Complex' => 'mathbb{C}', 'Dagger' => 'ddagger', 'diamonds' => 'diamondsuit', 'Doteq' => 'doteqdot', 'doublecap' => 'Cap', 'doublecup' => 'Cup', 'empty' => 'emptyset', 'Epsilon' => 'mathrm{E}', 'Eta' => 'mathrm{H}', 'exist' => 'exists', 'ge' => 'geq', 'gggtr' => 'ggg', 'hAar' => 'Leftrightarrow', 'harr' => 'leftrightarrow', 'Harr' => 'Leftrightarrow', 'hearts' => 'heartsuit', 'image' => 'Im', 'infin' => 'infty', 'Iota' => 'mathrm{I}', 'isin' => 'in', 'Kappa' => 'mathrm{K}', 'larr' => 'leftarrow', 'Larr' => 'Leftarrow', 'lArr' => 'Leftarrow', 'le' => 'leq', 'lrarr' => 'leftrightarrow', 'Lrarr' => 'Leftrightarrow', 'lrArr' => 'Leftrightarrow', 'Mu' => 'mathrm{M}', 'natnums' => 'mathbb{N}', 'ne' => 'neq', 'Nu' => 'mathrm{N}', 'O' => 'emptyset', 'omicron' => 'mathrm{o}', 'Omicron' => 'mathrm{O}', 'or' => 'lor', 'part' => 'partial', 'plusmn' => 'pm', 'rarr' => 'rightarrow', 'Rarr' => 'Rightarrow', 'rArr' => 'Rightarrow', 'real' => 'Re', 'reals' => 'mathbb{R}', 'Reals' => 'mathbb{R}', 'restriction' => 'upharpoonright', 'Rho' => 'mathrm{P}', 'sdot' => 'cdot', 'sect' => 'S', 'spades' => 'spadesuit', 'sub' => 'subset', 'sube' => 'subseteq', 'supe' => 'supseteq', 'Tau' => 'mathrm{T}', 'thetasym' => 'vartheta', 'varcoppa' => 'mbox{coppa}', 'weierp' => 'wp', 'Zeta' => 'mathrm{Z}', 'C' => 'mathbb{C}', 'H' => 'mathbb{H}', 'N' => 'mathbb{N}', 'Q' => 'mathbb{Q}', 'R' => 'mathbb{R}', 'Z' => 'mathbb{Z}');

	private $texFunc = array('arccos', 'arcsin', 'arctan', 'arg', 'cos', 'cosh', 'cot', 'coth', 'csc', 'deg', 'det', 'dim', 'exp', 'gcd', 'hom', 'inf', 'ker', 'lg', 'lim', 'liminf', 'limsup', 'ln', 'log', 'max', 'min', 'Pr', 'sec', 'sin', 'sinh', 'sup', 'tan', 'tanh', 'operatorname');
	private $texDecl = array('rm', 'it', 'cal');
	private $texOthers = array('left','right','sideset');

	private $outstr='';
	private $Brakets = array();
	private $argDepth = 0;
	private $expectArg = array();
	private $outbuf = '';
	private $wasSubSup = false;
	private $nextTokenIsArg = false;
	private $waitForEndOfTextMode = false;
	private $doubleRmClose = false;

	private function addArg($numArgs){
		$this->argDepth++;
		$this->expectArg[$this->argDepth] = $numArgs;
		$this->nextTokenIsArg = true;
	}
	private function appendBrakets(){
		global $debug;
		if( $this->argDepth && $this->expectArg[$this->argDepth] ==0 ){
			$this->addClosingBraket();
			$this->argDepth--;
			$this->nextTokenIsArg = false;
			if ($debug) $this->outbuf.="+";
			$this->appendBrakets();
		}
	}

	private function wasArg(){
		$this->expectArg[$this->argDepth]--;
		if( $this->expectArg[$this->argDepth] > 0){
			$this->nextTokenIsArg = true;
		} else {
			$this->nextTokenIsArg = false;
		}
	}
	private function addOpenBraket(){
		$this->outbuf.="{";
		if($this->nextTokenIsArg){
			$this->nextTokenIsArg = false;
			$this->Brakets[$this->argDepth]=1;
		}else { //if ( $this->argDepth ){
			if( array_key_exists($this->argDepth, $this->Brakets) ){
				$this->Brakets[$this->argDepth]++;
			} else {
				$this->Brakets[$this->argDepth] =1;
			}
		}
	}

	private function addClosingBraket(){
		global $debug;
		$this->outbuf.="}";
		if( $this->argDepth ){
			if( array_key_exists($this->argDepth, $this->Brakets) ){
				$this->Brakets[$this->argDepth]--;
				if ( $this->Brakets[$this->argDepth] == 0 ){
					$this->wasArg();
					// if( $this->doubleRmClose ){
					// 	$this->doubleRmClose = false;
					// 	$this->appendBrakets();
					// 	if ($this->argDepth ) {
					// 		$this->wasArg();
					// 	}
					// 	$this->outbuf.='}';
					// }
				}
			} else {
				//Arguments without brakets
				if ($debug){$this->outbuf.='µ';
				$this->outbuf.=$this->argDepth;}
				$this->wasArg();
				//$this->outbuf.='}';
			}
		}
	}

function checktex($tex = ''){
	global $lexer, $debug;
	try{
		$tokens = $lexer->lex($tex);
	} catch (Exception $e) {
		if ( $debug )
			echo($e->getMessage());
		return "S";
	}
	if( $lexer->hasPushedStates() ){
		if ( $debug )
			echo "still hasPushedStates\n";
		return "S";
	}
	if( $lexer->getStateStack() !== array('MATHMODE')){
		if ( $debug ){
			var_dump( $lexer->getStateStack()) ;
			echo "not in mathmode\n";
		}
		return "E";
	}


	foreach ($tokens as $value) {
		$type = $value[0];
		$content = $value[2];
		$this->outbuf = $content;
		if($this->waitForEndOfTextMode){
			if ($type != 'TEXT') {
				$this->waitForEndOfTextMode = false;
				//TODO add closing braket?
				$this->outbuf ="}".$this->outbuf;
			}
		}
		//var_export($this->outbuf);
		switch ($type):
			case 'COMMANDNAME':
				if ( $this->nextTokenIsArg ){
					$this->wasArg();
				}
				if (in_array($content, $this->texFunAr1) ){
					$this->outstr= substr($this->outstr,0,-1);
					$this->outbuf= '';
					$this->addOpenBraket();
					$this->outbuf.="\\".$content;
					$this->addArg(1);
				} elseif ( in_array($content, $this->texFunAr2)) {
					$this->outstr= substr($this->outstr,0,-1)."{\\";
					$this->addArg(2);
				} elseif ( in_array($content, $this->texBig)) {
					$this->outstr= substr($this->outstr,0,-1)."{\\";
					$this->addArg(1);
				} elseif ( in_array($content, $this->texDecl)) {
					$this->outstr= substr($this->outstr,0,-1)."{\\";
					$this->outbuf = $this->outbuf.'{';
					$this->addArg(1);
					$this->nextTokenIsArg = false;
					$this->Brakets[$this->argDepth]=1;
					$this->doubleRmClose = true;
				} elseif (array_key_exists($content, $this->mwLiterals)){
					$this->outbuf = $this->mwLiterals[$this->outbuf];
				} elseif ( in_array( $content, $this->texBoxChar)){
					$this->outbuf = 'mbox{\\' . $this->outbuf . '}';
				} elseif (array_key_exists($content, $this->mwDelimiter)){
					$this->outbuf = $this->mwDelimiter[$this->outbuf];
				} elseif (array_key_exists($content, $this->mwFunAr1)){
					$this->outstr= substr($this->outstr,0,-1);
					$this->outbuf= '';
					$this->addOpenBraket();
					$this->outbuf.="\\".$content;
					$this->addArg(1);
					$this->outbuf = $this->mwFunAr1[$this->outbuf];
				} elseif ( in_array( $content, $this->texLiterals)){
				} elseif ( in_array( $content, $this->texFunc)){
				} elseif ( in_array( $content, $this->texDelimiter)){
				} elseif ( in_array( $content, $this->texFunInfix)){
				} elseif ( in_array( $content, $this->texOthers)){
				} else { if( $debug ){
						echo "invalid COMMANDNAME '$content'\n";
					}
					return('F\\'.$content);
				}
				break;
			case '_^':
				if($this->wasSubSup){
					if ( $debug ){
						echo "double su(b|per)script";
					}
					return 'S';
				}
				$this->addOpenBraket();
				$this->addArg(1);
				$this->wasSubSup=true;
				break;
			case '}':
				$this->outbuf='';
				$this->addClosingBraket();
				break;
			case '{':
				$this->outbuf='';
				$this->addOpenBraket();
				break;
			case 'TEXTCOMMANDNAME':
				$this->outstr= substr($this->outstr,0,-1)."{\\";
				$this->waitForEndOfTextMode = true;
				break;
			case 'MWESCAPE':
				$this->outbuf = '\\'.$content;
				break;
			case 'WHITESPACE':
			case '\\':
			case 'TEXT':
				break;
			default:
				if( $this->nextTokenIsArg ){
					$this->wasArg();
				}
				if (substr($type,0,3) == 'err') {
					if ( $debug ) echo 'error';
					return "S";
				}
		endswitch;
		$this->appendBrakets();
		//var_dump( $this->content,$this->expectArg);
		if ($type != '_^' && $type != 'WHITESPACE'){
			$this->wasSubSup = false;
		}
		$this->outstr.= $this->outbuf;
			$this->outbuf='';
	}
	if( $this->argDepth ){
		$this->outstr.='}';
		if($debug){
			$this->outstr.='--';
		}
		if ($this->doubleRmClose){
			$this->outstr.='}';
		}
	}
	return '+'.$this->outstr;
}
}
$factory = new UsingCompiledRegexFactory(new LexerDataGenerator);
// 'a+b\\sin(x^2)asdfasdf  \\cosh k \\Pr x \\% =5'
$lexer = $factory->createLexer($lexerDefinition,'i','MATHMODE');
$checker = new CheckTex();
$debug = true;
$s= $checker->checktex($argv[1]);
echo $s;
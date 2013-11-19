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

	private $texFunAr1 = array('acute', 'bar', 'bcancel', 'bmod', 'boldsymbol', 'breve', 'cancel', 'check', 'ddot', 'dot', 'emph', 'grave', 'hat', 'mathbb', 'mathbf', 'mathbin', 'mathcal', 'mathclose', 'mathfrak', 'mathit', 'mathop', 'mathopen', 'mathord', 'mathpunct', 'mathrel', 'mathrm', 'mathsf', 'mathtt', /*'operatorname',*/ 'pmod', 'sqrt', 'textbf', 'textit', 'textrm', 'textsf', 'texttt', 'tilde', 'vec', 'xcancel', 'xleftarrow', 'xrightarrow', 'begin' ,'end',
		//customizations
		);

	private $texFunAr2 = array('binom', 'cancelto', 'cfrac', 'dbinom', 'dfrac', 'frac', 'overset', 'stackrel', 'tbinom', 'tfrac', 'underset');

	private $texFunInfix = array('atop', 'choose', 'over');

	private $texBoxChar = array('Coppa', 'coppa', 'Digamma', 'euro', 'geneuro', 'geneuronarrow', 'geneurowide', 'Koppa', 'koppa', 'officialeuro', 'Sampi', 'sampi', 'Stigma', 'stigma', 'varstigma');

	private $mwDelimiter = array('darr' => 'downarrow', 'dArr' => 'Downarrow', 'Darr' => 'Downarrow', 'lang' => 'langle', 'rang' => 'rangle', 'uarr' => 'uparrow', 'uArr' => 'Uparrow', 'Uarr' => 'Uparrow');

	private $mwFunAr1 = array('Bbb' => 'mathbb', 'bold' => 'mathbf');
/**@var array replacement of mediawiki specific function in the form 'custom_command' => 'tex_command'**/
	private $mwLiterals = array('alef' => 'aleph', 'alefsym' => 'aleph', 'Alpha' => 'mathrm{A}', 'and' => 'land', 'ang' => 'angle', 'Beta' => 'mathrm{B}', 'bull' => 'bullet', 'Chi' => 'mathrm{X}', 'clubs' => 'clubsuit', 'cnums' => 'mathbb{C}', 'Complex' => 'mathbb{C}', 'Dagger' => 'ddagger', 'diamonds' => 'diamondsuit', 'Doteq' => 'doteqdot', 'doublecap' => 'Cap', 'doublecup' => 'Cup', 'empty' => 'emptyset', 'Epsilon' => 'mathrm{E}', 'Eta' => 'mathrm{H}', 'exist' => 'exists', 'ge' => 'geq', 'gggtr' => 'ggg', 'hAar' => 'Leftrightarrow', 'harr' => 'leftrightarrow', 'Harr' => 'Leftrightarrow', 'hearts' => 'heartsuit', 'image' => 'Im', 'infin' => 'infty', 'Iota' => 'mathrm{I}', 'isin' => 'in', 'Kappa' => 'mathrm{K}', 'larr' => 'leftarrow', 'Larr' => 'Leftarrow', 'lArr' => 'Leftarrow', 'le' => 'leq', 'lrarr' => 'leftrightarrow', 'Lrarr' => 'Leftrightarrow', 'lrArr' => 'Leftrightarrow', 'Mu' => 'mathrm{M}', 'natnums' => 'mathbb{N}', 'ne' => 'neq', 'Nu' => 'mathrm{N}', 'O' => 'emptyset', 'omicron' => 'mathrm{o}', 'Omicron' => 'mathrm{O}', 'or' => 'lor', 'part' => 'partial', 'plusmn' => 'pm', 'rarr' => 'rightarrow', 'Rarr' => 'Rightarrow', 'rArr' => 'Rightarrow', 'real' => 'Re', 'reals' => 'mathbb{R}', 'Reals' => 'mathbb{R}', 'restriction' => 'upharpoonright', 'Rho' => 'mathrm{P}', 'sdot' => 'cdot', 'sect' => 'S', 'spades' => 'spadesuit', 'sub' => 'subset', 'sube' => 'subseteq', 'supe' => 'supseteq', 'Tau' => 'mathrm{T}', 'thetasym' => 'vartheta', 'varcoppa' => 'mbox{coppa}', 'weierp' => 'wp', 'Zeta' => 'mathrm{Z}', 'C' => 'mathbb{C}', 'H' => 'mathbb{H}', 'N' => 'mathbb{N}', 'Q' => 'mathbb{Q}', 'R' => 'mathbb{R}', 'Z' => 'mathbb{Z}');

	private $texFunc = array('arccos', 'arcsin', 'arctan', 'arg', 'cos', 'cosh', 'cot', 'coth', 'csc', 'deg', 'det', 'dim', 'exp', 'gcd', 'hom', 'inf', 'ker', 'lg', 'lim', 'liminf', 'limsup', 'ln', 'log', 'max', 'min', 'Pr', 'sec', 'sin', 'sinh', 'sup', 'tan', 'tanh', 'operatorname');
	private $texDecl = array(/*'rm',*/ 'it', 'cal');
	private $texOthers = array('left','right','sideset');

	/**@var String the output**/
	private $outstr='';
	/**@var array the current braket state **/
	private $Brakets = array();
	/**@var array the current structure of expected arguments **/
	private $expectArg = array();
	/**@var int the depth of $expectedArg **/
	private $argDepth = 0;
	/** @var String buffet to be attached to the output **/
	private $outbuf = '';
	/** @var Boolean stores if last token was ^_ to avoid constructs like $a__1$ **/
	private $wasSubSup = false;
	/** @var boolean determines if the next token is an agument **/
	private $nextTokenIsArg = false;
	/** @var boolean is in text mode */
	private $waitForEndOfTextMode = false;

	private $extraBrakets = array();
	private $inArg =0;
	private $outgroups = array();
	private $afterHatGroup = 0;
	private $hatGroupLevel = 0;
	private $hatGroupContent = '';
	private $subGroupLevel = 0;

	private function getValue(&$level, &$array){
		if ( array_key_exists( $level, $array)){
			return $array[$level];
		} else {
			$this->debug("WARN: No args defined at level".$level);
			return 0;
		}
	}
	private function getArg(){
		return $this->getValue( $this->argDepth, $this->expectArg);
	}

	private function getExtraBrakets(){
		return $this->getValue( $this->argDepth, $this->extraBrakets);
	}

	private function getBrakets(){
		return $this->getValue( $this->argDepth, $this->Brakets);
	}
	private function addCommandInBrakets($command){
		$this->outbuf= substr($this->outbuf,0,-1);
		$this->addOpenBraket(true);
		$this->outbuf.="\\".$command;
	}
	private function debug($msg){
		global $debug;
		if ($debug){
			echo "  ".$msg."\n";
		}
	}
	/**
	 *
	 * @param int $numArgs the number of required arguments
	 */
	private function addArg($numArgs){
		$this->argDepth++;
		$this->expectArg[$this->argDepth] = $numArgs;
		$this->nextTokenIsArg = true;
		$this->debug("Adding ".$this->getArg()." argument(s) at level $this->argDepth");
	}
	/**
	 * This function is called after an argument was processed
	 *
	 */
	private function appendBrakets(){
		if ( array_key_exists( $this->argDepth, $this->extraBrakets)){
			if ($this->extraBrakets[ $this->argDepth ] >0 && $this->expectArg[$this->argDepth]==1 ){
				$this->debug("add extra }");
				$this->addClosingBraket();
				$this->extraBrakets[ $this->argDepth ]--;
				$this->appendBrakets();
			}
		}
	}
	private function singleTokenArg(){
		if($this->nextTokenIsArg){
			$this->enterArg();
			$this->leaveArg();
		}
	}

	private function enterArg(){
		if($this->nextTokenIsArg){
			$this->inArg++;
			$this->nextTokenIsArg = false;
			$this->debug("entering argument ".$this->getArg()."(".$this->argDepth .")");
		}
	}
	/**
	}
	 * function is called after an argument was finished
	 */
	private function leaveArg() {
		//check if an Argument was expected
		if($this->inArg && $this->getBrakets() == $this->getExtraBrakets()){
			$this->debug("leaving argument ".$this->getArg()."(".$this->argDepth .")");
			$this->appendBrakets();
			$this->expectArg[ $this->argDepth ]--;
			$this->inArg--;
			if ( $this->getArg() > 0 ) {
				$this->nextTokenIsArg = true;
			} else {
				$this->nextTokenIsArg = false;
				if($this->argDepth == $this->hatGroupLevel){
					$this->afterHatGroup=2;
					$this->debug("after head grpup");
					$this->hatGroupLevel =0;
				} elseif ($this->argDepth == $this->subGroupLevel){
					$this->debug("after sub group");
					$this->outgroups[].=$this->outbuf;
					$this->outbuf='';
					$this->outgroups[].=$this->hatGroupContent;
					$this->hatGroupContent = '';
					$this->subGroupLevel = 0;
				}
				$this->argDepth--;
				$this->leaveArg();
			}
		}
	}
	private function addOpenBraket($extra = false){
		$this->outbuf.="{";
		if( array_key_exists($this->argDepth, $this->Brakets) ){
			$this->Brakets[$this->argDepth]++;
		} else {
			$this->Brakets[$this->argDepth] = 1;
		}
		if ( $extra ){
			if( array_key_exists($this->argDepth, $this->extraBrakets) ){
				$this->extraBrakets[ $this->argDepth ]++;
			} else {
				$this->extraBrakets[ $this->argDepth ] = 1;
			}
		}
		$this->debug("adding ".($extra?"extra":"normal")."{. Now ".$this->Brakets[$this->argDepth]." { open at level ". $this->argDepth);
	}

	private function addClosingBraket(){
		$this->outbuf.="}";
		if( array_key_exists($this->argDepth, $this->Brakets) ){
			$this->Brakets[$this->argDepth]--;
		} else {
			$this->debug("missing key!!");
		}
	}

function checktex($tex = ''){
	global $lexer, $debug;
	try{
		$tokens = $lexer->lex($tex);
	} catch (Exception $e) {
		$this->debug($e->getMessage());
		return "S";
	}
	if( $lexer->hasPushedStates() ){
		$this->debug("still hasPushedStates");
		return "S";
	}
	if( $lexer->getStateStack() !== array('MATHMODE')){
		if ( $debug ){
			var_dump( $lexer->getStateStack()) ;
			echo "not in mathmode\n";
		}
		return "E";
	}

	$wasHat=false;
	foreach ($tokens as $value) {
		$type = $value[0];
		$content = $value[2];
		//$this->outbuf = $content;
		if($this->waitForEndOfTextMode){
			if ($type != 'TEXT') {
				$this->waitForEndOfTextMode = false;
				$this->outbuf .="}";
				$this->leaveArg();
			}
		}
		if ($debug){
			echo "processing token '$content' of type '$type'";
			if($this->nextTokenIsArg){
				echo " expecting argument ";
			}
			echo var_export($this->afterHatGroup,true);
			echo "\n";
		}
		switch ($type):
			case 'COMMANDNAME':
				$this->enterArg();
				if (in_array($content, $this->texFunAr1) ){
					$this->addArg(1);
					$this->addCommandInBrakets($content);
				} elseif ( in_array($content, $this->texFunAr2)) {
					$this->addArg(2);
					$this->addCommandInBrakets($content);
				} elseif ( in_array($content, $this->texBig)) {
					$this->addArg(1);
					$this->addCommandInBrakets($content);
				} elseif ( in_array($content, $this->texDecl)) {
					$this->addArg(1);
					$this->addCommandInBrakets($content);
					//$this->nextTokenIsArg = false;
					//$this->Brakets[$this->argDepth]=1;
				} elseif (array_key_exists($content, $this->mwFunAr1)){
					$this->addArg(1);
					$this->addCommandInBrakets($this->mwFunAr1[$content]);
				} elseif (array_key_exists($content, $this->mwLiterals)){
					$this->outbuf .= $this->mwLiterals[$content];
					$this->leaveArg();
				} elseif ( in_array( $content, $this->texBoxChar)){
					$this->outbuf .= 'mbox{\\' . $content . '}';
					$this->leaveArg();
				} elseif (array_key_exists($content, $this->mwDelimiter)){
					$this->outbuf .= $this->mwDelimiter[$content];
					$this->leaveArg();
				} elseif ( in_array( $content, $this->texLiterals)
					|| in_array( $content, $this->texFunc)
					|| in_array( $content, $this->texDelimiter)
					|| in_array( $content, $this->texFunInfix)
					|| in_array( $content, $this->texOthers)){
					$this->outbuf.=$content;
					$this->leaveArg();
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
				//handle ugly a^b_c->a_c^b rewriting
				if($content == "^" ){
					$this->outgroups[]= $this->outbuf;
					$this->outbuf = '';
					$this->hatGroupLevel = $this->argDepth+1;
					$wasHat=true;
					$this->debug("found head group");
				}
				if($content == "_" && $this->afterHatGroup>0){
					$this->debug('found _ in wrong order');
					$this->hatGroupContent = $this->outbuf;
					$this->outbuf = '';
					$this->subGroupLevel = $this->argDepth+1;
				}
				$this->outbuf .= $content;
				$this->addArg(1);
				$this->addOpenBraket(true);
				$this->wasSubSup=true;
				break;
			case '}':
				$this->addClosingBraket();
				$this->leaveArg();
				break;
			case '{':
				$this->enterArg();
				$this->addOpenBraket();
				break;
			case 'TEXTCOMMANDNAME':
				$this->outbuf= substr($this->outbuf,0,-1)."{\\";
				$this->outbuf.= $content;
				$this->waitForEndOfTextMode = true;
				$this->enterArg();
				break;
			case 'MWESCAPE':
				$this->outbuf .= '\\'.$content;
				$this->singleTokenArg();
				break;
			case 'WHITESPACE':
			case '\\':
			case 'TEXT':
				$this->outbuf .= $content;
				break;
			default:
				$this->outbuf .= $content;
				$this->singleTokenArg();
				if (substr($type,0,3) == 'err') {
					if ( $debug ) echo 'error';
					return "S";
				}
		endswitch;
		if ($type != 'WHITESPACE'){
			if ($type != '_^' ){
				$this->wasSubSup = false;
			}
			$this->afterHatGroup--;
		}
		//$this->outstr.= $this->outbuf;
		$this->debug("setting output to ".$this->outbuf);
	}
	$this->outstr = implode( '', $this->outgroups ) . $this->outbuf;
	if( $this->argDepth ){
		$this->outstr .= '}';
		if($debug){
			$this->outstr .= '--';
		}
	}
	//var_dump($this->extraBrakets);
	return '+'.$this->outstr;
}
}
$factory = new UsingCompiledRegexFactory(new LexerDataGenerator);
// 'a+b\\sin(x^2)asdfasdf  \\cosh k \\Pr x \\% =5'
$lexer = $factory->createLexer($lexerDefinition,'i','MATHMODE');
$checker = new CheckTex();
#$debug = true;
$s= $checker->checktex($argv[1]);
echo $s;
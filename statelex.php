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
		'[0-9a-zA-Z\+\(\)\s\=\-\,\*\:/\.\;\?!\`´\[\]\>\<\|]'=> 'MATHCHAR',
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
		'[a-zA-Z]+' => function(Stateful $lexer) {
				$lexer->swapState('MATHMODE');
				return 'COMMANDNAME';
			}
	),
	'TEXTMODE' => array(
		'(\\\\[\{\}]|[^\{\}])' => function(Stateful $lexer){
			$lexer->swapState('MATHMODE');
			return 'plaintextchar';
			},
		'\{' => function(Stateful $lexer) {
			$lexer->swapState('MATHMODE');
			$lexer->pushState('LONGTEXTMODE');
			return 'TEXT-{';
			},
		'\}' => 'err_}',
		),
	'LONGTEXTMODE' => array(
		'(\\\\[\{\}]|[^\{\}])+' => 'plaintext',
		'\{' => function(Stateful $lexer) {
			$lexer->pushState('TEXTMODE');
			return 'TEXT-{';
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
	);

class CheckTex{

	private $texLiterals = array('AA', 'aleph', 'alpha', 'amalg', 'And', 'angle', 'approx', 'approxeq', 'ast', 'asymp', 'backepsilon', 'backprime', 'backsim', 'backsimeq', 'barwedge', 'Bbbk', 'because', 'beta', 'beth', 'between', 'bigcap', 'bigcirc', 'bigcup', 'bigodot', 'bigoplus', 'bigotimes', 'bigsqcup', 'bigstar', 'bigtriangledown', 'bigtriangleup', 'biguplus', 'bigvee', 'bigwedge', 'blacklozenge', 'blacksquare', 'blacktriangle', 'blacktriangledown', 'blacktriangleleft', 'blacktriangleright', 'bot', 'bowtie', 'Box', 'boxdot', 'boxminus', 'boxplus', 'boxtimes', 'bullet', 'bumpeq', 'Bumpeq', 'cap', 'Cap', 'cdot', 'cdots', 'centerdot', 'checkmark', 'chi', 'circ', 'circeq', 'circlearrowleft', 'circlearrowright', 'circledast', 'circledcirc', 'circleddash', 'circledS', 'clubsuit', 'colon', 'color', 'complement', 'cong', 'coprod', 'cup', 'Cup', 'curlyeqprec', 'curlyeqsucc', 'curlyvee', 'curlywedge', 'curvearrowleft', 'curvearrowright', 'dagger', 'daleth', 'dashv', 'ddagger', 'ddots', 'definecolor', 'delta', 'Delta', 'diagdown', 'diagup', 'diamond', 'Diamond', 'diamondsuit', 'digamma', 'displaystyle', 'div', 'divideontimes', 'doteq', 'doteqdot', 'dotplus', 'dots', 'dotsb', 'dotsc', 'dotsi', 'dotsm', 'dotso', 'doublebarwedge', 'downdownarrows', 'downharpoonleft', 'downharpoonright', 'ell', 'emptyset', 'epsilon', 'eqcirc', 'eqsim', 'eqslantgtr', 'eqslantless', 'equiv', 'eta', 'eth', 'exists', 'fallingdotseq', 'Finv', 'flat', 'forall', 'frown', 'Game', 'gamma', 'Gamma', 'geq', 'geqq', 'geqslant', 'gets', 'gg', 'ggg', 'gimel', 'gnapprox', 'gneq', 'gneqq', 'gnsim', 'gtrapprox', 'gtrdot', 'gtreqless', 'gtreqqless', 'gtrless', 'gtrsim', 'gvertneqq', 'hbar', 'heartsuit', 'hline', 'hookleftarrow', 'hookrightarrow', 'hslash', 'iff', 'iiiint', 'iiint', 'iint', 'Im', 'imath', 'implies', 'in', 'infty', 'injlim', 'int', 'intercal', 'iota', 'jmath', 'kappa', 'lambda', 'Lambda', 'land', 'ldots', 'leftarrow', 'Leftarrow', 'leftarrowtail', 'leftharpoondown', 'leftharpoonup', 'leftleftarrows', 'leftrightarrow', 'Leftrightarrow', 'leftrightarrows', 'leftrightharpoons', 'leftrightsquigarrow', 'leftthreetimes', 'leq', 'leqq', 'leqslant', 'lessapprox', 'lessdot', 'lesseqgtr', 'lesseqqgtr', 'lessgtr', 'lesssim', 'limits', 'll', 'Lleftarrow', 'lll', 'lnapprox', 'lneq', 'lneqq', 'lnot', 'lnsim', 'longleftarrow', 'Longleftarrow', 'longleftrightarrow', 'Longleftrightarrow', 'longmapsto', 'longrightarrow', 'Longrightarrow', 'looparrowleft', 'looparrowright', 'lor', 'lozenge', 'Lsh', 'ltimes', 'lVert', 'lvertneqq', 'mapsto', 'measuredangle', 'mho', 'mid', 'mod', 'models', 'mp', 'mu', 'multimap', 'nabla', 'natural', 'ncong', 'nearrow', 'neg', 'neq', 'nexists', 'ngeq', 'ngeqq', 'ngeqslant', 'ngtr', 'ni', 'nleftarrow', 'nLeftarrow', 'nleftrightarrow', 'nLeftrightarrow', 'nleq', 'nleqq', 'nleqslant', 'nless', 'nmid', 'nolimits', 'not', 'notin', 'nparallel', 'nprec', 'npreceq', 'nrightarrow', 'nRightarrow', 'nshortmid', 'nshortparallel', 'nsim', 'nsubseteq', 'nsubseteqq', 'nsucc', 'nsucceq', 'nsupseteq', 'nsupseteqq', 'ntriangleleft', 'ntrianglelefteq', 'ntriangleright', 'ntrianglerighteq', 'nu', 'nvdash', 'nVdash', 'nvDash', 'nVDash', 'nwarrow', 'odot', 'oint', 'omega', 'Omega', 'ominus', 'oplus', 'oslash', 'otimes', 'overbrace', 'overleftarrow', 'overleftrightarrow', 'overline', 'overrightarrow', 'P', 'pagecolor', 'parallel', 'partial', 'perp', 'phi', 'Phi', 'pi', 'Pi', 'pitchfork', 'pm', 'prec', 'precapprox', 'preccurlyeq', 'preceq', 'precnapprox', 'precneqq', 'precnsim', 'precsim', 'prime', 'prod', 'projlim', 'propto', 'psi', 'Psi', 'qquad', 'quad', 'Re', 'rho', 'rightarrow', 'Rightarrow', 'rightarrowtail', 'rightharpoondown', 'rightharpoonup', 'rightleftarrows', 'rightrightarrows', 'rightsquigarrow', 'rightthreetimes', 'risingdotseq', 'Rrightarrow', 'Rsh', 'rtimes', 'rVert', 'S', 'scriptscriptstyle', 'scriptstyle', 'searrow', 'setminus', 'sharp', 'shortmid', 'shortparallel', 'sigma', 'Sigma', 'sim', 'simeq', 'smallfrown', 'smallsetminus', 'smallsmile', 'smile', 'spadesuit', 'sphericalangle', 'sqcap', 'sqcup', 'sqsubset', 'sqsubseteq', 'sqsupset', 'sqsupseteq', 'square', 'star', 'subset', 'Subset', 'subseteq', 'subseteqq', 'subsetneq', 'subsetneqq', 'succ', 'succapprox', 'succcurlyeq', 'succeq', 'succnapprox', 'succneqq', 'succnsim', 'succsim', 'sum', 'supset', 'Supset', 'supseteq', 'supseteqq', 'supsetneq', 'supsetneqq', 'surd', 'swarrow', 'tau', 'textstyle', 'textvisiblespace', 'therefore', 'theta', 'Theta', 'thickapprox', 'thicksim', 'times', 'to', 'top', 'triangle', 'triangledown', 'triangleleft', 'trianglelefteq', 'triangleq', 'triangleright', 'trianglerighteq', 'underbrace', 'underline', 'upharpoonleft', 'upharpoonright', 'uplus', 'upsilon', 'Upsilon', 'upuparrows', 'varepsilon', 'varinjlim', 'varkappa', 'varliminf', 'varlimsup', 'varnothing', 'varphi', 'varpi', 'varprojlim', 'varpropto', 'varrho', 'varsigma', 'varsubsetneq', 'varsubsetneqq', 'varsupsetneq', 'varsupsetneqq', 'vartheta', 'vartriangle', 'vartriangleleft', 'vartriangleright', 'vdash', 'Vdash', 'vDash', 'vdots', 'vee', 'veebar', 'vline', 'Vvdash', 'wedge', 'widehat', 'widetilde', 'wp', 'wr', 'xi', 'Xi', 'zeta');


	private $texBig = array('big', 'Big', 'bigg', 'Bigg', 'biggl', 'Biggl', 'biggr', 'Biggr', 'bigl', 'Bigl', 'bigr', 'Bigr');

	private $texDelimiter = array('backslash', 'downarrow', 'Downarrow', 'langle', 'lbrace', 'lceil', 'lfloor', 'llcorner', 'lrcorner', 'rangle', 'rbrace', 'rceil', 'rfloor', 'rightleftharpoons', 'twoheadleftarrow', 'twoheadrightarrow', 'ulcorner', 'uparrow', 'Uparrow', 'updownarrow', 'Updownarrow', 'urcorner', 'Vert', 'vert', 'lbrack', 'rbrack');

	private $texFunAr1 = array('acute', 'bar', 'bcancel', 'bmod', 'boldsymbol', 'breve', 'cancel', 'check', 'ddot', 'dot', 'emph', 'grave', 'hat', 'mathbb', 'mathbf', 'mathbin', 'mathcal', 'mathclose', 'mathfrak', 'mathit', 'mathop', 'mathopen', 'mathord', 'mathpunct', 'mathrel', 'mathrm', 'mathsf', 'mathtt', 'operatorname', 'pmod', 'sqrt', 'textbf', 'textit', 'textrm', 'textsf', 'texttt', 'tilde', 'vec', 'xcancel', 'xleftarrow', 'xrightarrow');

	private $texFunAr2 = array('binom', 'cancelto', 'cfrac', 'dbinom', 'dfrac', 'frac', 'overset', 'stackrel', 'tbinom', 'tfrac', 'underset');

	private $texFunInfix = array('atop', 'choose', 'over');

	private $texBoxChar = array('Coppa', 'coppa', 'Digamma', 'euro', 'geneuro', 'geneuronarrow', 'geneurowide', 'Koppa', 'koppa', 'officialeuro', 'Sampi', 'sampi', 'Stigma', 'stigma', 'varstigma');

	private $mwDelimiter = array('darr' => 'downarrow', 'dArr' => 'Downarrow', 'Darr' => 'Downarrow', 'lang' => 'langle', 'rang' => 'rangle', 'uarr' => 'uparrow', 'uArr' => 'Uparrow', 'Uarr' => 'Uparrow');

	private $mwFunAr1 = array('Bbb' => 'mathbb', 'bold' => 'mathbf');


	private $mwLiterals = array('alef' => 'aleph', 'alefsym' => 'aleph', 'Alpha' => 'mathrm{A}', 'and' => 'land', 'ang' => 'angle', 'Beta' => 'mathrm{B}', 'bull' => 'bullet', 'Chi' => 'mathrm{X}', 'clubs' => 'clubsuit', 'cnums' => 'mathbb{C}', 'Complex' => 'mathbb{C}', 'Dagger' => 'ddagger', 'diamonds' => 'diamondsuit', 'Doteq' => 'doteqdot', 'doublecap' => 'Cap', 'doublecup' => 'Cup', 'empty' => 'emptyset', 'Epsilon' => 'mathrm{E}', 'Eta' => 'mathrm{H}', 'exist' => 'exists', 'ge' => 'geq', 'gggtr' => 'ggg', 'hAar' => 'Leftrightarrow', 'harr' => 'leftrightarrow', 'Harr' => 'Leftrightarrow', 'hearts' => 'heartsuit', 'image' => 'Im', 'infin' => 'infty', 'Iota' => 'mathrm{I}', 'isin' => 'in', 'Kappa' => 'mathrm{K}', 'larr' => 'leftarrow', 'Larr' => 'Leftarrow', 'lArr' => 'Leftarrow', 'le' => 'leq', 'lrarr' => 'leftrightarrow', 'Lrarr' => 'Leftrightarrow', 'lrArr' => 'Leftrightarrow', 'Mu' => 'mathrm{M}', 'natnums' => 'mathbb{N}', 'ne' => 'neq', 'Nu' => 'mathrm{N}', 'O' => 'emptyset', 'omicron' => 'mathrm{o}', 'Omicron' => 'mathrm{O}', 'or' => 'lor', 'part' => 'partial', 'plusmn' => 'pm', 'rarr' => 'rightarrow', 'Rarr' => 'Rightarrow', 'rArr' => 'Rightarrow', 'real' => 'Re', 'reals' => 'mathbb{R}', 'Reals' => 'mathbb{R}', 'restriction' => 'upharpoonright', 'Rho' => 'mathrm{P}', 'sdot' => 'cdot', 'sect' => 'S', 'spades' => 'spadesuit', 'sub' => 'subset', 'sube' => 'subseteq', 'supe' => 'supseteq', 'Tau' => 'mathrm{T}', 'thetasym' => 'vartheta', 'varcoppa' => 'mbox{coppa}', 'weierp' => 'wp', 'Zeta' => 'mathrm{Z}', 'C' => 'mathbb{C}', 'H' => 'mathbb{H}', 'N' => 'mathbb{N}', 'Q' => 'mathbb{Q}', 'R' => 'mathbb{R}', 'Z' => 'mathbb{Z}');

	private $texFunc = array('arccos', 'arcsin', 'arctan', 'arg', 'cos', 'cosh', 'cot', 'coth', 'csc', 'deg', 'det', 'dim', 'exp', 'gcd', 'hom', 'inf', 'ker', 'lg', 'lim', 'liminf', 'limsup', 'ln', 'log', 'max', 'min', 'Pr', 'sec', 'sin', 'sinh', 'sup', 'tan', 'tanh');

private $spc = array(
	'darr' => 'downarrow',
	'dArr' => 'Downarrow',
	'Darr' => 'Downarrow',
	'lang' => 'langle',
	'rang' => 'rangle',
	'uarr' => 'uparrow',
	'uArr' => 'Uparrow',
	'Uarr' => 'Uparrow',
	'alef' => 'aleph',
	'alefsym' => 'aleph',
	'Alpha' => 'mathrm{A}',
	'and' => 'land',
	'ang' => 'angle',
	'Beta' => 'mathrm{B}',
	'bull' => 'bullet',
	'Chi' => 'mathrm{X}',
	'clubs' => 'clubsuit',
	'cnums' => 'mathbb{C}',
	'Complex' => 'mathbb{C}',
	'Dagger' => 'ddagger',
	'diamonds' => 'diamondsuit',
	'Doteq' => 'doteqdot',
	'doublecap' => 'Cap',
	'doublecup' => 'Cup',
	'empty' => 'emptyset',
	'Epsilon' => 'mathrm{E}',
	'Eta' => 'mathrm{H}',
	'exist' => 'exists',
	'ge' => 'geq',
	'gggtr' => 'ggg',
	'hAar' => 'Leftrightarrow',
	'harr' => 'leftrightarrow',
	'Harr' => 'Leftrightarrow',
	'hearts' => 'heartsuit',
	'image' => 'Im',
	'infin' => 'infty',
	'Iota' => 'mathrm{I}',
	'isin' => 'in',
	'Kappa' => 'mathrm{K}',
	'larr' => 'leftarrow',
	'Larr' => 'Leftarrow',
	'lArr' => 'Leftarrow',
	'le' => 'leq',
	'lrarr' => 'leftrightarrow',
	'Lrarr' => 'Leftrightarrow',
	'lrArr' => 'Leftrightarrow',
	'Mu' => 'mathrm{M}',
	'natnums' => 'mathbb{N}',
	'ne' => 'neq',
	'Nu' => 'mathrm{N}',
	'O' => 'emptyset',
	'omicron' => 'mathrm{o}',
	'Omicron' => 'mathrm{O}',
	'or' => 'lor',
	'part' => 'partial',
	'plusmn' => 'pm',
	'rarr' => 'rightarrow',
	'Rarr' => 'Rightarrow',
	'rArr' => 'Rightarrow',
	'real' => 'Re',
	'reals' => 'mathbb{R}',
	'Reals' => 'mathbb{R}',
	'restriction' => 'upharpoonright',
	'Rho' => 'mathrm{P}',
	'sdot' => 'cdot',
	'sect' => 'S',
	'spades' => 'spadesuit',
	'sub' => 'subset',
	'sube' => 'subseteq',
	'supe' => 'supseteq',
	'Tau' => 'mathrm{T}',
	'thetasym' => 'vartheta',
	'varcoppa' => 'mbox{coppa}',
	'weierp' => 'wp',
	'Zeta' => 'mathrm{Z}',
	'C' => 'mathbb{C}',
	'H' => 'mathbb{H}',
	'N' => 'mathbb{N}',
	'Q' => 'mathbb{Q}',
	'R' => 'mathbb{R}',
	'Z' => 'mathbb{Z}',
);
private $validCommands = array('leftrightsquigarrow', 'blacktriangleright', 'longleftrightarrow', 'Longleftrightarrow', 'overleftrightarrow', 'blacktriangledown', 'blacktriangleleft', 'leftrightharpoons', 'rightleftharpoons', 'scriptscriptstyle', 'twoheadrightarrow', 'circlearrowright', 'downharpoonright', 'ntrianglerighteq', 'rightharpoondown', 'rightrightarrows', 'textvisiblespace', 'twoheadleftarrow', 'vartriangleright', 'bigtriangledown', 'circlearrowleft', 'curvearrowright', 'downharpoonleft', 'leftharpoondown', 'leftrightarrows', 'nleftrightarrow', 'nLeftrightarrow', 'ntrianglelefteq', 'rightleftarrows', 'rightsquigarrow', 'rightthreetimes', 'trianglerighteq', 'vartriangleleft', 'curvearrowleft', 'doublebarwedge', 'downdownarrows', 'hookrightarrow', 'leftleftarrows', 'leftrightarrow', 'Leftrightarrow', 'leftthreetimes', 'longrightarrow', 'Longrightarrow', 'looparrowright', 'nshortparallel', 'ntriangleright', 'overrightarrow', 'rightarrowtail', 'rightharpoonup', 'sphericalangle', 'trianglelefteq', 'upharpoonright', 'bigtriangleup', 'blacktriangle', 'divideontimes', 'fallingdotseq', 'geneuronarrow', 'hookleftarrow', 'leftarrowtail', 'leftharpoonup', 'longleftarrow', 'Longleftarrow', 'looparrowleft', 'measuredangle', 'ntriangleleft', 'overleftarrow', 'shortparallel', 'smallsetminus', 'triangleright', 'upharpoonleft', 'varsubsetneqq', 'varsupsetneqq', 'blacklozenge', 'displaystyle', 'officialeuro', 'operatorname', 'risingdotseq', 'triangledown', 'triangleleft', 'varsubsetneq', 'varsupsetneq', 'backepsilon', 'blacksquare', 'circledcirc', 'circleddash', 'curlyeqprec', 'curlyeqsucc', 'definecolor', 'diamondsuit', 'eqslantless', 'geneurowide', 'nrightarrow', 'nRightarrow', 'preccurlyeq', 'precnapprox', 'restriction', 'Rrightarrow', 'scriptstyle', 'succcurlyeq', 'succnapprox', 'thickapprox', 'updownarrow', 'Updownarrow', 'vartriangle', 'xrightarrow', 'boldsymbol', 'circledast', 'complement', 'curlywedge', 'eqslantgtr', 'gtreqqless', 'lessapprox', 'lesseqqgtr', 'Lleftarrow', 'longmapsto', 'nleftarrow', 'nLeftarrow', 'nsubseteqq', 'nsupseteqq', 'precapprox', 'rightarrow', 'Rightarrow', 'smallfrown', 'smallsmile', 'sqsubseteq', 'sqsupseteq', 'subsetneqq', 'succapprox', 'supsetneqq', 'underbrace', 'upuparrows', 'varepsilon', 'varnothing', 'varprojlim', 'xleftarrow', 'backprime', 'backsimeq', 'backslash', 'bigotimes', 'centerdot', 'checkmark', 'doublecap', 'doublecup', 'downarrow', 'Downarrow', 'gtrapprox', 'gtreqless', 'gvertneqq', 'heartsuit', 'leftarrow', 'Leftarrow', 'lesseqgtr', 'lvertneqq', 'mathclose', 'mathpunct', 'ngeqslant', 'nleqslant', 'nparallel', 'nshortmid', 'nsubseteq', 'nsupseteq', 'overbrace', 'pagecolor', 'pitchfork', 'spadesuit', 'subseteqq', 'subsetneq', 'supseteqq', 'supsetneq', 'textstyle', 'therefore', 'triangleq', 'underline', 'varinjlim', 'varliminf', 'varlimsup', 'varpropto', 'varstigma', 'widetilde', 'approxeq', 'barwedge', 'bigoplus', 'bigsqcup', 'biguplus', 'bigwedge', 'boxminus', 'boxtimes', 'cancelto', 'circledS', 'clubsuit', 'curlyvee', 'diagdown', 'diamonds', 'doteqdot', 'emptyset', 'geqslant', 'gnapprox', 'intercal', 'leqslant', 'llcorner', 'lnapprox', 'lrcorner', 'mathfrak', 'mathopen', 'multimap', 'nolimits', 'overline', 'parallel', 'precneqq', 'precnsim', 'setminus', 'shortmid', 'sqsubset', 'sqsupset', 'stackrel', 'subseteq', 'succneqq', 'succnsim', 'supseteq', 'thetasym', 'thicksim', 'triangle', 'ulcorner', 'underset', 'urcorner', 'varcoppa', 'varkappa', 'varsigma', 'vartheta', 'alefsym', 'backsim', 'bcancel', 'because', 'between', 'bigcirc', 'bigodot', 'bigstar', 'boxplus', 'Complex', 'ddagger', 'diamond', 'Diamond', 'digamma', 'Digamma', 'dotplus', 'epsilon', 'Epsilon', 'geneuro', 'gtrless', 'implies', 'lessdot', 'lessgtr', 'lesssim', 'lozenge', 'mathbin', 'mathcal', 'mathord', 'mathrel', 'natnums', 'natural', 'nearrow', 'nexists', 'npreceq', 'nsucceq', 'nwarrow', 'omicron', 'Omicron', 'overset', 'partial', 'precsim', 'projlim', 'searrow', 'sideset', 'succsim', 'swarrow', 'uparrow', 'Uparrow', 'upsilon', 'Upsilon', 'widehat', 'xcancel', 'approx', 'arccos', 'arcsin', 'arctan', 'bigcap', 'bigcup', 'bigvee', 'bowtie', 'boxdot', 'bullet', 'bumpeq', 'Bumpeq', 'cancel', 'choose', 'circeq', 'coprod', 'dagger', 'Dagger', 'daleth', 'dbinom', 'diagup', 'eqcirc', 'exists', 'forall', 'gtrdot', 'gtrsim', 'hearts', 'hslash', 'iiiint', 'injlim', 'lambda', 'Lambda', 'langle', 'lbrace', 'lbrack', 'lfloor', 'liminf', 'limits', 'limsup', 'ltimes', 'mapsto', 'mathbb', 'mathbf', 'mathit', 'mathop', 'mathrm', 'mathsf', 'mathtt', 'models', 'nvdash', 'nVdash', 'nvDash', 'nVDash', 'ominus', 'oslash', 'otimes', 'plusmn', 'preceq', 'propto', 'rangle', 'rbrace', 'rbrack', 'rfloor', 'rtimes', 'spades', 'square', 'Stigma', 'stigma', 'subset', 'Subset', 'succeq', 'supset', 'Supset', 'tbinom', 'textbf', 'textit', 'textrm', 'textsf', 'texttt', 'varphi', 'varrho', 'veebar', 'Vvdash', 'weierp', 'acute', 'aleph', 'alpha', 'Alpha', 'amalg', 'angle', 'asymp', 'biggl', 'Biggl', 'biggr', 'Biggr', 'binom', 'breve', 'cdots', 'cfrac', 'check', 'clubs', 'cnums', 'colon', 'color', 'Coppa', 'coppa', 'dashv', 'ddots', 'delta', 'Delta', 'dfrac', 'doteq', 'Doteq', 'dotsb', 'dotsc', 'dotsi', 'dotsm', 'dotso', 'empty', 'eqsim', 'equiv', 'exist', 'frown', 'gamma', 'Gamma', 'gggtr', 'gimel', 'gneqq', 'gnsim', 'grave', 'hline', 'iiint', 'image', 'imath', 'infin', 'infty', 'jmath', 'kappa', 'Kappa', 'Koppa', 'koppa', 'lceil', 'ldots', 'lneqq', 'lnsim', 'lrarr', 'Lrarr', 'lrArr', 'lVert', 'nabla', 'ncong', 'ngeqq', 'nleqq', 'nless', 'notin', 'nprec', 'nsucc', 'omega', 'Omega', 'oplus', 'prime', 'qquad', 'rceil', 'reals', 'Reals', 'right', 'rVert', 'Sampi', 'sampi', 'sharp', 'sigma', 'Sigma', 'simeq', 'smile', 'sqcap', 'sqcup', 'tfrac', 'theta', 'Theta', 'tilde', 'times', 'uplus', 'varpi', 'vdash', 'Vdash', 'vDash', 'vdots', 'vline', 'wedge', 'alef', 'atop', 'Bbbk', 'beta', 'Beta', 'beth', 'bigg', 'Bigg', 'bigl', 'Bigl', 'bigr', 'Bigr', 'bmod', 'bold', 'bull', 'cdot', 'circ', 'cong', 'cosh', 'coth', 'darr', 'dArr', 'Darr', 'ddot', 'dots', 'emph', 'euro', 'Finv', 'flat', 'frac', 'Game', 'geqq', 'gets', 'gneq', 'hAar', 'harr', 'Harr', 'hbar', 'hbox', 'iint', 'iota', 'Iota', 'isin', 'land', 'lang', 'larr', 'Larr', 'lArr', 'left', 'leqq', 'lneq', 'lnot', 'mbox', 'ngeq', 'ngtr', 'nleq', 'nmid', 'nsim', 'odot', 'oint', 'over', 'part', 'perp', 'pmod', 'prec', 'prod', 'quad', 'rang', 'rarr', 'Rarr', 'rArr', 'real', 'sdot', 'sect', 'sinh', 'sqrt', 'star', 'sube', 'succ', 'supe', 'surd', 'tanh', 'text', 'uarr', 'uArr', 'Uarr', 'vbox', 'Vert', 'vert', 'zeta', 'Zeta', 'And', 'and', 'ang', 'arg', 'ast', 'bar', 'Bbb', 'big', 'Big', 'bot', 'Box', 'cal', 'cap', 'Cap', 'chi', 'Chi', 'cos', 'cot', 'csc', 'cup', 'Cup', 'deg', 'det', 'dim', 'div', 'dot', 'ell', 'eta', 'Eta', 'eth', 'exp', 'gcd', 'geq', 'ggg', 'hat', 'hom', 'iff', 'inf', 'int', 'ker', 'leq', 'lim', 'lll', 'log', 'lor', 'Lsh', 'max', 'mho', 'mid', 'min', 'mod', 'neg', 'neq', 'not', 'phi', 'Phi', 'psi', 'Psi', 'rho', 'Rho', 'Rsh', 'sec', 'sim', 'sin', 'sub', 'sum', 'sup', 'tan', 'tau', 'Tau', 'top', 'vec', 'vee', 'AA', 'bf', 'ge', 'gg', 'Im', 'in', 'it', 'le', 'lg', 'll', 'ln', 'mp', 'mu', 'Mu', 'ne', 'ni', 'nu', 'Nu', 'or', 'pi', 'Pi', 'pm', 'Pr', 'Re', 'rm', 'to', 'wp', 'wr', 'xi', 'Xi', 'C', 'H', 'N', 'O', 'P', 'Q', 'R', 'S', 'Z', );
private $f1 = array('acute', 'bar', 'bcancel', 'bmod', 'boldsymbol', 'breve', 'cancel', 'check', 'ddot', 'dot', 'emph', 'grave', 'hat', 'mathbb', 'mathbf', 'mathbin', 'mathcal', 'mathclose', 'mathfrak', 'mathit', 'mathop', 'mathopen', 'mathord', 'mathpunct', 'mathrel', 'mathrm', 'mathsf', 'mathtt', 'operatorname', 'pmod', 'sqrt', 'textbf', 'textit', 'textrm', 'textsf', 'texttt', 'tilde', 'vec', 'xcancel', 'xleftarrow', 'xrightarrow');
private $f2=array('binom', 'cancelto', 'cfrac', 'dbinom', 'dfrac', 'frac', 'overset', 'stackrel', 'tbinom', 'tfrac', 'underset');
private $fbig= array('big', 'Big', 'bigg', 'Bigg', 'biggl', 'Biggl', 'biggr', 'Biggr', 'bigl', 'Bigl', 'bigr', 'Bigr');
	private $outstr='';
	private $Brakets = array();
	private $argDepth = 0;
	private $expectArg = array();
	private $content = '';
	private $wasSubSup = false;
	private $nextTokenIsArg = false;

	private function addArg($numArgs){
		$this->argDepth++;
		$this->expectArg[$this->argDepth] = $numArgs;
		$this->nextTokenIsArg = true;
	}
	private function wasArg(){
		$this->expectArg[$this->argDepth]--;
		if( $this->expectArg[$this->argDepth] ){
			$this->nextTokenIsArg = true;
		} else {
			$this->nextTokenIsArg = false;
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
		return "S";
	}


	foreach ($tokens as $value) {
		$type = $value[0];
		$this->content = $value[2];
		switch ($type):
			case 'COMMANDNAME':
				if (!in_array($this->content, $this->validCommands)){
					if( $debug ){
						echo "invalid COMMANDNAME\n";
					}
					return('F\\'.$this->content);
				}
				if (in_array($this->content, $this->f1) && $this->content != 'operatorname'  ){
					$this->outstr= substr($this->outstr,0,-1)."{\\";
					$this->addArg(1);
				} elseif ( in_array($this->content, $this->f2)) {
					$this->outstr= substr($this->outstr,0,-1)."{\\";
					$this->addArg(2);
				} elseif ( in_array($this->content, $this->fbig)) {
					$this->outstr= substr($this->outstr,0,-1)."{\\";
					$this->addArg(1);
				} elseif (array_key_exists($this->content, $this->spc)){
					$this->content = $this->spc[$this->content];
				} elseif ( $this->nextTokenIsArg ){
					$this->wasArg();
				}
				break;
			case '_^':
				if($this->wasSubSup){
					if ( $debug ){
						echo "double su(b|per)script";
					}
					return 'S';
				}
				$this->content = $this->content.'{';
				$this->addArg(1);
				$this->wasSubSup=true;
				break;
			case '}':
				if( $this->argDepth ){
					$this->Brakets[$this->argDepth]--;
					if ( $this->Brakets[$this->argDepth] == 0 ){
						$this->wasArg();
					}
				}
				break;
			case '{':
				if($this->nextTokenIsArg){
					$this->nextTokenIsArg = false;
					$this->Brakets[$this->argDepth]=1;
				}elseif ( $this->argDepth ){
					if( array_key_exists($this->argDepth, $this->Brakets) ){
						$this->Brakets[$this->argDepth]++;
					} else {
						$this->Brakets[$this->argDepth] =1;
					}
				}
				break;
			case 'WHITESPACE':
			case '\\':
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
		//var_dump( $this->content,$this->expectArg);
		if( $this->argDepth && $this->expectArg[$this->argDepth] ==0 ){
			$this->argDepth--;
			$this->nextTokenIsArg = false;
			$this->content.='}';
		}
		if ($type != '_^' && $type != 'WHITESPACE'){
			$this->wasSubSup = false;
		}
		$this->outstr.= $this->content;
			$this->content='';
	}
	if( $this->argDepth ){
		$this->outstr.='}';
	}
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
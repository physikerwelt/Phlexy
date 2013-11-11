<?php
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

require 'lib/Phlexy/bootstrap.php';
 $factory = new Phlexy\LexerFactory\Stateful\UsingCompiledRegex(
	new Phlexy\LexerDataGenerator
);
use Phlexy\Lexer\Stateful;

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


#include 'examples/phpLexerDefinition.php';
#$lexerDefinition = getPHPLexerDefinition();
// The "i" is an additional modifier (all createLexer methods accept it)
$lexer = $factory->createLexer($lexerDefinition,'i','MATHMODE');

class CheckTex{

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
	private $BraketsOpen = 0;
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


	foreach ($tokens as $key => $value) {
		$type = $value[0];
		$this->content .= $value[2];
		//var_export($value);
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
				if($wasSubSup)
					return 'S';
				$this->content = $this->content.'{';
				$this->addArg(1);
				$wasSubSup=true;
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
		if(	$this->argDepth && $this->expectArg[$this->argDepth] ==0 ){
			$this->argDepth--;
			$this->nextTokenIsArg = false;
			$this->content.='}';
		}
		if ($type != '_^' && $type != 'WHITESPACE'){
			$wasSubSup = false;
		}
		$this->outstr.= $this->content;
			$this->content='';
	}
	if( $this->argDepth ){
		//var_export($this->expectArg);
		$this->outstr.='}';
	}
	return '+'.$this->outstr;
}
}
// 'a+b\\sin(x^2)asdfasdf  \\cosh k \\Pr x \\% =5'
$checker = new CheckTex();
$debug = true;
$s= $checker->checktex($argv[1]);
echo $s;
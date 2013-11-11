<?php
require 'lib/Phlexy/bootstrap.php';
$grpSpace = '[ \t\n\r]';
$grpAlpha = '[a-zA-Z]';
$grpLiteralId = '[a-zA-Z]';
$grpLiteralMn = '[0-9]';
$grpLiteralUfLt = '[,:;\?\!\']';
$grpDelimiterUfLt = '[\(\)\.]';
$grpLiteralUfOp = '[+-*=]';
$grpDelimiterUfOp = '[/|]';
$grpBoxchars  = '[0-9a-zA-Z+-*,=\(\):/;?.!\\` \128-\255]';
$grpAboxchars = '[0-9a-zA-Z+-*,=\(\):/;?.!\\` ]';

$validCommands = array('leftrightsquigarrow', 'blacktriangleright', 'longleftrightarrow', 'Longleftrightarrow', 'overleftrightarrow', 'blacktriangledown', 'blacktriangleleft', 'leftrightharpoons', 'rightleftharpoons', 'scriptscriptstyle', 'twoheadrightarrow', 'circlearrowright', 'downharpoonright', 'ntrianglerighteq', 'rightharpoondown', 'rightrightarrows', 'textvisiblespace', 'twoheadleftarrow', 'vartriangleright', 'bigtriangledown', 'circlearrowleft', 'curvearrowright', 'downharpoonleft', 'leftharpoondown', 'leftrightarrows', 'nleftrightarrow', 'nLeftrightarrow', 'ntrianglelefteq', 'rightleftarrows', 'rightsquigarrow', 'rightthreetimes', 'trianglerighteq', 'vartriangleleft', 'curvearrowleft', 'doublebarwedge', 'downdownarrows', 'hookrightarrow', 'leftleftarrows', 'leftrightarrow', 'Leftrightarrow', 'leftthreetimes', 'longrightarrow', 'Longrightarrow', 'looparrowright', 'nshortparallel', 'ntriangleright', 'overrightarrow', 'rightarrowtail', 'rightharpoonup', 'sphericalangle', 'trianglelefteq', 'upharpoonright', 'bigtriangleup', 'blacktriangle', 'divideontimes', 'fallingdotseq', 'geneuronarrow', 'hookleftarrow', 'leftarrowtail', 'leftharpoonup', 'longleftarrow', 'Longleftarrow', 'looparrowleft', 'measuredangle', 'ntriangleleft', 'overleftarrow', 'shortparallel', 'smallsetminus', 'triangleright', 'upharpoonleft', 'varsubsetneqq', 'varsupsetneqq', 'blacklozenge', 'displaystyle', 'officialeuro', 'operatorname', 'risingdotseq', 'triangledown', 'triangleleft', 'varsubsetneq', 'varsupsetneq', 'backepsilon', 'blacksquare', 'circledcirc', 'circleddash', 'curlyeqprec', 'curlyeqsucc', 'definecolor', 'diamondsuit', 'eqslantless', 'geneurowide', 'nrightarrow', 'nRightarrow', 'preccurlyeq', 'precnapprox', 'restriction', 'Rrightarrow', 'scriptstyle', 'succcurlyeq', 'succnapprox', 'thickapprox', 'updownarrow', 'Updownarrow', 'vartriangle', 'xrightarrow', 'boldsymbol', 'circledast', 'complement', 'curlywedge', 'eqslantgtr', 'gtreqqless', 'lessapprox', 'lesseqqgtr', 'Lleftarrow', 'longmapsto', 'nleftarrow', 'nLeftarrow', 'nsubseteqq', 'nsupseteqq', 'precapprox', 'rightarrow', 'Rightarrow', 'smallfrown', 'smallsmile', 'sqsubseteq', 'sqsupseteq', 'subsetneqq', 'succapprox', 'supsetneqq', 'underbrace', 'upuparrows', 'varepsilon', 'varnothing', 'varprojlim', 'xleftarrow', 'backprime', 'backsimeq', 'backslash', 'bigotimes', 'centerdot', 'checkmark', 'doublecap', 'doublecup', 'downarrow', 'Downarrow', 'gtrapprox', 'gtreqless', 'gvertneqq', 'heartsuit', 'leftarrow', 'Leftarrow', 'lesseqgtr', 'lvertneqq', 'mathclose', 'mathpunct', 'ngeqslant', 'nleqslant', 'nparallel', 'nshortmid', 'nsubseteq', 'nsupseteq', 'overbrace', 'pagecolor', 'pitchfork', 'spadesuit', 'subseteqq', 'subsetneq', 'supseteqq', 'supsetneq', 'textstyle', 'therefore', 'triangleq', 'underline', 'varinjlim', 'varliminf', 'varlimsup', 'varpropto', 'varstigma', 'widetilde', 'approxeq', 'barwedge', 'bigoplus', 'bigsqcup', 'biguplus', 'bigwedge', 'boxminus', 'boxtimes', 'cancelto', 'circledS', 'clubsuit', 'curlyvee', 'diagdown', 'diamonds', 'doteqdot', 'emptyset', 'geqslant', 'gnapprox', 'intercal', 'leqslant', 'llcorner', 'lnapprox', 'lrcorner', 'mathfrak', 'mathopen', 'multimap', 'nolimits', 'overline', 'parallel', 'precneqq', 'precnsim', 'setminus', 'shortmid', 'sqsubset', 'sqsupset', 'stackrel', 'subseteq', 'succneqq', 'succnsim', 'supseteq', 'thetasym', 'thicksim', 'triangle', 'ulcorner', 'underset', 'urcorner', 'varcoppa', 'varkappa', 'varsigma', 'vartheta', 'alefsym', 'backsim', 'bcancel', 'because', 'between', 'bigcirc', 'bigodot', 'bigstar', 'boxplus', 'Complex', 'ddagger', 'diamond', 'Diamond', 'digamma', 'Digamma', 'dotplus', 'epsilon', 'Epsilon', 'geneuro', 'gtrless', 'implies', 'lessdot', 'lessgtr', 'lesssim', 'lozenge', 'mathbin', 'mathcal', 'mathord', 'mathrel', 'natnums', 'natural', 'nearrow', 'nexists', 'npreceq', 'nsucceq', 'nwarrow', 'omicron', 'Omicron', 'overset', 'partial', 'precsim', 'projlim', 'searrow', 'sideset', 'succsim', 'swarrow', 'uparrow', 'Uparrow', 'upsilon', 'Upsilon', 'widehat', 'xcancel', 'approx', 'arccos', 'arcsin', 'arctan', 'bigcap', 'bigcup', 'bigvee', 'bowtie', 'boxdot', 'bullet', 'bumpeq', 'Bumpeq', 'cancel', 'choose', 'circeq', 'coprod', 'dagger', 'Dagger', 'daleth', 'dbinom', 'diagup', 'eqcirc', 'exists', 'forall', 'gtrdot', 'gtrsim', 'hearts', 'hslash', 'iiiint', 'injlim', 'lambda', 'Lambda', 'langle', 'lbrace', 'lbrack', 'lfloor', 'liminf', 'limits', 'limsup', 'ltimes', 'mapsto', 'mathbb', 'mathbf', 'mathit', 'mathop', 'mathrm', 'mathsf', 'mathtt', 'models', 'nvdash', 'nVdash', 'nvDash', 'nVDash', 'ominus', 'oslash', 'otimes', 'plusmn', 'preceq', 'propto', 'rangle', 'rbrace', 'rbrack', 'rfloor', 'rtimes', 'spades', 'square', 'Stigma', 'stigma', 'subset', 'Subset', 'succeq', 'supset', 'Supset', 'tbinom', 'textbf', 'textit', 'textrm', 'textsf', 'texttt', 'varphi', 'varrho', 'veebar', 'Vvdash', 'weierp', 'acute', 'aleph', 'alpha', 'Alpha', 'amalg', 'angle', 'asymp', 'biggl', 'Biggl', 'biggr', 'Biggr', 'binom', 'breve', 'cdots', 'cfrac', 'check', 'clubs', 'cnums', 'colon', 'color', 'Coppa', 'coppa', 'dashv', 'ddots', 'delta', 'Delta', 'dfrac', 'doteq', 'Doteq', 'dotsb', 'dotsc', 'dotsi', 'dotsm', 'dotso', 'empty', 'eqsim', 'equiv', 'exist', 'frown', 'gamma', 'Gamma', 'gggtr', 'gimel', 'gneqq', 'gnsim', 'grave', 'hline', 'iiint', 'image', 'imath', 'infin', 'infty', 'jmath', 'kappa', 'Kappa', 'Koppa', 'koppa', 'lceil', 'ldots', 'lneqq', 'lnsim', 'lrarr', 'Lrarr', 'lrArr', 'lVert', 'nabla', 'ncong', 'ngeqq', 'nleqq', 'nless', 'notin', 'nprec', 'nsucc', 'omega', 'Omega', 'oplus', 'prime', 'qquad', 'rceil', 'reals', 'Reals', 'right', 'rVert', 'Sampi', 'sampi', 'sharp', 'sigma', 'Sigma', 'simeq', 'smile', 'sqcap', 'sqcup', 'tfrac', 'theta', 'Theta', 'tilde', 'times', 'uplus', 'varpi', 'vdash', 'Vdash', 'vDash', 'vdots', 'vline', 'wedge', 'alef', 'atop', 'Bbbk', 'beta', 'Beta', 'beth', 'bigg', 'Bigg', 'bigl', 'Bigl', 'bigr', 'Bigr', 'bmod', 'bold', 'bull', 'cdot', 'circ', 'cong', 'cosh', 'coth', 'darr', 'dArr', 'Darr', 'ddot', 'dots', 'emph', 'euro', 'Finv', 'flat', 'frac', 'Game', 'geqq', 'gets', 'gneq', 'hAar', 'harr', 'Harr', 'hbar', 'hbox', 'iint', 'iota', 'Iota', 'isin', 'land', 'lang', 'larr', 'Larr', 'lArr', 'left', 'leqq', 'lneq', 'lnot', 'mbox', 'ngeq', 'ngtr', 'nleq', 'nmid', 'nsim', 'odot', 'oint', 'over', 'part', 'perp', 'pmod', 'prec', 'prod', 'quad', 'rang', 'rarr', 'Rarr', 'rArr', 'real', 'sdot', 'sect', 'sinh', 'sqrt', 'star', 'sube', 'succ', 'supe', 'surd', 'tanh', 'text', 'uarr', 'uArr', 'Uarr', 'vbox', 'Vert', 'vert', 'zeta', 'Zeta', 'And', 'and', 'ang', 'arg', 'ast', 'bar', 'Bbb', 'big', 'Big', 'bot', 'Box', 'cal', 'cap', 'Cap', 'chi', 'Chi', 'cos', 'cot', 'csc', 'cup', 'Cup', 'deg', 'det', 'dim', 'div', 'dot', 'ell', 'eta', 'Eta', 'eth', 'exp', 'gcd', 'geq', 'ggg', 'hat', 'hom', 'iff', 'inf', 'int', 'ker', 'leq', 'lim', 'lll', 'log', 'lor', 'Lsh', 'max', 'mho', 'mid', 'min', 'mod', 'neg', 'neq', 'not', 'phi', 'Phi', 'psi', 'Psi', 'rho', 'Rho', 'Rsh', 'sec', 'sim', 'sin', 'sub', 'sum', 'sup', 'tan', 'tau', 'Tau', 'top', 'vec', 'vee', 'AA', 'bf', 'ge', 'gg', 'Im', 'in', 'it', 'le', 'lg', 'll', 'ln', 'mp', 'mu', 'Mu', 'ne', 'ni', 'nu', 'Nu', 'or', 'pi', 'Pi', 'pm', 'Pr', 'Re', 'rm', 'to', 'wp', 'wr', 'xi', 'Xi', 'C', 'H', 'N', 'O', 'P', 'Q', 'R', 'S', 'Z', );


 $factory = new Phlexy\LexerFactory\Stateful\UsingCompiledRegex(
	new Phlexy\LexerDataGenerator
);
use Phlexy\Lexer\Stateful;
$commandfunction = function(Stateful $lexer) {
		$lexer->swapState('MATHMODE');
		return 'COMMANDNAME';
	};
$cmdarray = array();
foreach ($validCommands as $command) {
	$cmdarray[$command] = &$commandfunction;
}
$lexerDefinition = array(
	'MATHMODE' => array(
		'\\\\' => function(Stateful $lexer) {
			$lexer->swapState('COMMANDNAME');

			return '\\';
		},
		$grpBoxchars => function(Stateful $lexer) {
			//$lexer->swapState('MATHMODE');

			return 'MATHMODE';
		},//*/
	),
	'COMMANDNAME' => $cmdarray,
	'ATERCOMMAND' => array(
		'[0-9\s\n\r\t\]'
		)
);


include 'examples/phpLexerDefinition.php';
#$lexerDefinition = getPHPLexerDefinition();
// The "i" is an additional modifier (all createLexer methods accept it)
$lexer = $factory->createLexer($lexerDefinition);
$tokens = $lexer->lex('a+b\\sin(x^2) \\cosh k \\Pr x');
var_dump($tokens);
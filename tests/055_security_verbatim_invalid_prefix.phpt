--TEST--
Security: verbatim string with non-alnum prefix falls back to type=""
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
// =5 + "\x00\x00\x00:x" — three NUL bytes as the prefix, then ':' separator
$p->feed("=5\r\n\x00\x00\x00:x\r\n");
$v = $p->next();
var_dump($v instanceof Resp3\VerbatimString);
var_dump($v->type);                       // "" because NULs are not alnum
var_dump($v->value);                      // full payload preserved
var_dump(strlen($v->value));

// Valid alnum prefix still works the normal way
$p2 = new Resp3\Parser();
$p2->feed("=15\r\ntxt:Some string\r\n");
$ok = $p2->next();
var_dump($ok->type);
var_dump($ok->value);
?>
--EXPECTF--
bool(true)
string(0) ""
string(5) "%s%s%s:x"
int(5)
string(3) "txt"
string(11) "Some string"

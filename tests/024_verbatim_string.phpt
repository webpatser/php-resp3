--TEST--
RESP3: verbatim string (=) wraps in Resp3\VerbatimString
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("=15\r\ntxt:Some string\r\n=18\r\nmkd:# Hello world!\r\n");
$a = $p->next();
$b = $p->next();
var_dump($a instanceof Resp3\VerbatimString);
echo "a.type=$a->type a.value=$a->value\n";
var_dump($b instanceof Resp3\VerbatimString);
echo "b.type=$b->type b.value=$b->value\n";
?>
--EXPECT--
bool(true)
a.type=txt a.value=Some string
bool(true)
b.type=mkd b.value=# Hello world!

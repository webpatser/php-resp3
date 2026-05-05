--TEST--
RESP3: big number (() returns string (PHP has no native bignum)
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("(3492890328409238509324850943850943825024385\r\n");
$out = $p->next();
var_dump($out);
var_dump(gettype($out));
?>
--EXPECT--
string(43) "3492890328409238509324850943850943825024385"
string(6) "string"

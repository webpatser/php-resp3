--TEST--
RESP2: null array (*-1) maps to PHP null
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("*-1\r\n");
$out = $p->next();
var_dump($out);
var_dump($out === null);
?>
--EXPECT--
NULL
bool(true)

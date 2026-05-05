--TEST--
RESP3: null (_) maps to PHP null
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("_\r\n");
$out = $p->next();
var_dump($out);
var_dump($out === null);
?>
--EXPECT--
NULL
bool(true)

--TEST--
RESP2: simple string (+)
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("+OK\r\n");
var_dump($p->hasNext());
var_dump($p->next());
var_dump($p->hasNext());
?>
--EXPECT--
bool(true)
string(2) "OK"
bool(false)

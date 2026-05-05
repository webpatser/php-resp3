--TEST--
RESP3: boolean (#t / #f)
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("#t\r\n#f\r\n");
var_dump($p->next());
var_dump($p->next());
?>
--EXPECT--
bool(true)
bool(false)

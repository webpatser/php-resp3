--TEST--
Security: lastAttributes() is one-shot — second read returns null
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("|1\r\n+key\r\n+value\r\n+payload\r\n");
$msg = $p->next();
var_dump($msg);
var_dump($p->lastAttributes());           // first read: array
var_dump($p->lastAttributes());           // second read: null (consumed)
?>
--EXPECT--
string(7) "payload"
array(1) {
  ["key"]=>
  string(5) "value"
}
NULL

--TEST--
Attributes belong to the message that follows them and do not leak to later messages
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("|1\r\n+ttl\r\n:3600\r\n:1\r\n:2\r\n");
var_dump($p->next());
var_dump($p->lastAttributes());
var_dump($p->next());
var_dump($p->lastAttributes());

// Unconsumed attributes must not leak onto the next message either
$p = new Resp3\Parser();
$p->feed("|1\r\n+a\r\n:1\r\n:10\r\n:20\r\n");
var_dump($p->next());
var_dump($p->next());
var_dump($p->lastAttributes());
?>
--EXPECT--
int(1)
array(1) {
  ["ttl"]=>
  int(3600)
}
int(2)
NULL
int(10)
int(20)
NULL

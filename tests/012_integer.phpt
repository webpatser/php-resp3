--TEST--
RESP2: integer (:) including signed extremes
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed(":0\r\n:123\r\n:-9223372036854775808\r\n:9223372036854775807\r\n");
var_dump($p->next());
var_dump($p->next());
var_dump($p->next());
var_dump($p->next());
?>
--EXPECT--
int(0)
int(123)
int(-9223372036854775808)
int(9223372036854775807)

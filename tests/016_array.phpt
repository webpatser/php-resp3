--TEST--
RESP2: flat array (*) of integers
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("*3\r\n:1\r\n:2\r\n:3\r\n");
var_dump($p->next());
?>
--EXPECT--
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}

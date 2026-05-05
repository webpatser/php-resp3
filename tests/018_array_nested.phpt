--TEST--
RESP2: nested arrays of mixed types
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("*2\r\n*2\r\n:1\r\n:2\r\n*1\r\n+ok\r\n");
var_dump($p->next());
?>
--EXPECT--
array(2) {
  [0]=>
  array(2) {
    [0]=>
    int(1)
    [1]=>
    int(2)
  }
  [1]=>
  array(1) {
    [0]=>
    string(2) "ok"
  }
}

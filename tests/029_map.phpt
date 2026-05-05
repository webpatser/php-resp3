--TEST--
RESP3: map (%) maps to PHP associative array
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("%3\r\n+a\r\n:1\r\n+b\r\n:2\r\n+c\r\n*2\r\n+x\r\n+y\r\n%0\r\n");
var_dump($p->next());
var_dump($p->next());
?>
--EXPECT--
array(3) {
  ["a"]=>
  int(1)
  ["b"]=>
  int(2)
  ["c"]=>
  array(2) {
    [0]=>
    string(1) "x"
    [1]=>
    string(1) "y"
  }
}
array(0) {
}

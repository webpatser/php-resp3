--TEST--
RESP3: set (~) maps to PHP indexed array (no native set)
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("~3\r\n+a\r\n+b\r\n+c\r\n~0\r\n");
var_dump($p->next());
var_dump($p->next());
?>
--EXPECT--
array(3) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
  [2]=>
  string(1) "c"
}
array(0) {
}

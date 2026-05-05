--TEST--
RESP3: attribute (|) attaches metadata, retrievable via lastAttributes()
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("|1\r\n+key-popularity\r\n%2\r\n+a\r\n,0.1923\r\n+b\r\n,0.0012\r\n+after-attr\r\n");
$msg = $p->next();
var_dump($msg);
var_dump($p->lastAttributes());
?>
--EXPECT--
string(10) "after-attr"
array(1) {
  ["key-popularity"]=>
  array(2) {
    ["a"]=>
    float(0.1923)
    ["b"]=>
    float(0.0012)
  }
}

--TEST--
Edge: attribute frame followed by an empty aggregate value
--EXTENSIONS--
resp3
--FILE--
<?php
// |1 +meta +data    *0     -- attribute, then empty array as the value
$p = new Resp3\Parser();
$p->feed("|1\r\n+meta\r\n+data\r\n*0\r\n");

$value = $p->next();
var_dump($value);                          // []
$attrs = $p->lastAttributes();
var_dump($attrs);                          // ['meta' => 'data']

// And again with an empty map as the value
$p2 = new Resp3\Parser();
$p2->feed("|1\r\n+key\r\n+val\r\n%0\r\n");
$value2 = $p2->next();
var_dump($value2);                         // []
var_dump($p2->lastAttributes());           // ['key' => 'val']
?>
--EXPECT--
array(0) {
}
array(1) {
  ["meta"]=>
  string(4) "data"
}
array(0) {
}
array(1) {
  ["key"]=>
  string(3) "val"
}

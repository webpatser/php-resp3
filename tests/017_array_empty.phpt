--TEST--
RESP2: empty array (*0)
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("*0\r\n");
var_dump($p->next());
?>
--EXPECT--
array(0) {
}

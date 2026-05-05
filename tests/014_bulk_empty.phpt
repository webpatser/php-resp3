--TEST--
RESP2: empty bulk string ($0)
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("\$0\r\n\r\n");
$out = $p->next();
var_dump($out);
var_dump(strlen($out));
?>
--EXPECT--
string(0) ""
int(0)

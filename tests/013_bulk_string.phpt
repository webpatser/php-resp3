--TEST--
RESP2: bulk string ($) with binary-safe payload
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("\$5\r\nhello\r\n\$12\r\nbin\x00\x01\x02ary!!!\r\n");
var_dump($p->next());
$msg = $p->next();
var_dump(strlen($msg));
var_dump(bin2hex($msg));
?>
--EXPECT--
string(5) "hello"
int(12)
string(24) "62696e000102617279212121"

--TEST--
RESP3: blob error (!) returns Resp3\RedisException with binary-safe message
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("!21\r\nSYNTAX invalid syntax\r\n");
$err = $p->next();
var_dump($err instanceof Resp3\RedisException);
echo $err->getMessage(), "\n";
?>
--EXPECT--
bool(true)
SYNTAX invalid syntax

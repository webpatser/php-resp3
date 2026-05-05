--TEST--
Error: -WRONGTYPE prefix preserved verbatim
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("-WRONGTYPE Operation against a key holding the wrong kind of value\r\n");
$err = $p->next();
var_dump($err instanceof Resp3\RedisException);
$msg = $err->getMessage();
echo $msg, "\n";
// Userland routing relies on the prefix being intact
var_dump(str_starts_with($msg, 'WRONGTYPE'));
?>
--EXPECT--
bool(true)
WRONGTYPE Operation against a key holding the wrong kind of value
bool(true)

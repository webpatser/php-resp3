--TEST--
Error: -MOVED cluster redirect preserved with slot and target address
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("-MOVED 1234 127.0.0.1:6380\r\n");
$err = $p->next();
var_dump($err instanceof Resp3\RedisException);
$msg = $err->getMessage();
echo $msg, "\n";
// Cluster routing code splits on space: ["MOVED", "1234", "127.0.0.1:6380"]
$parts = explode(' ', $msg);
var_dump($parts[0]);
var_dump($parts[1]);
var_dump($parts[2]);
?>
--EXPECT--
bool(true)
MOVED 1234 127.0.0.1:6380
string(5) "MOVED"
string(4) "1234"
string(14) "127.0.0.1:6380"

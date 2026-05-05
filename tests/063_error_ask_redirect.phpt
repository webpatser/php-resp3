--TEST--
Error: -ASK cluster redirect preserved
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("-ASK 4567 redis-node-2.internal:6379\r\n");
$err = $p->next();
var_dump($err instanceof Resp3\RedisException);
echo $err->getMessage(), "\n";
$parts = explode(' ', $err->getMessage());
var_dump($parts[0]);
var_dump($parts[1]);
var_dump($parts[2]);
?>
--EXPECT--
bool(true)
ASK 4567 redis-node-2.internal:6379
string(3) "ASK"
string(4) "4567"
string(26) "redis-node-2.internal:6379"

--TEST--
RESP2: error reply (-) returns Resp3\RedisException instance
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("-ERR something failed\r\n");
$result = $p->next();
var_dump($result instanceof Resp3\RedisException);
echo $result->getMessage(), "\n";
?>
--EXPECT--
bool(true)
ERR something failed

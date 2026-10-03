--TEST--
Nested simple and blob errors are wrapped as RedisException (EXEC-shaped reply)
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("*3\r\n+OK\r\n-WRONGTYPE Operation\r\n!5\r\nERR x\r\n");
$r = $p->next();
var_dump(count($r));
var_dump($r[0]);
var_dump($r[1] instanceof Resp3\RedisException);
var_dump($r[1]->prefix);
var_dump($r[1]->getMessage());
var_dump($r[2] instanceof Resp3\RedisException);
var_dump($r[2]->prefix);
var_dump($r[2]->getMessage());
?>
--EXPECT--
int(3)
string(2) "OK"
bool(true)
string(9) "WRONGTYPE"
string(19) "WRONGTYPE Operation"
bool(true)
string(3) "ERR"
string(5) "ERR x"

--TEST--
RedisException::$prefix: leading uppercase token, empty for lowercase, PROTOCOL for parser faults
--EXTENSIONS--
resp3
--FILE--
<?php
foreach ([
    "-MOVED 3999 127.0.0.1:6381\r\n",
    "-ERR x\r\n",
    "-lowercase\r\n",
    "-ERR\r\n",
    "!5\r\nERR x\r\n",
] as $bytes) {
    $p = new Resp3\Parser();
    $p->feed($bytes);
    $e = $p->next();
    var_dump($e instanceof Resp3\RedisException);
    var_dump($e->prefix);
}

$p = new Resp3\Parser();
$p->feed("?junk\r\n");
try {
    $p->hasNext();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    var_dump($e->prefix);
}
?>
--EXPECT--
bool(true)
string(5) "MOVED"
bool(true)
string(3) "ERR"
bool(true)
string(0) ""
bool(true)
string(3) "ERR"
bool(true)
string(3) "ERR"
string(8) "PROTOCOL"

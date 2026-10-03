--TEST--
Protocol error latches the parser until reset()
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("?bad\r\n:1\r\n");
try {
    $p->hasNext();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    var_dump($e->prefix);
}
try {
    $p->hasNext();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    var_dump($e->prefix);
    var_dump(str_contains($e->getMessage(), 'error state'));
    var_dump(str_contains($e->getMessage(), 'reset()'));
}
$p->reset();
$p->feed(":1\r\n");
var_dump($p->hasNext());
var_dump($p->next());
?>
--EXPECT--
string(8) "PROTOCOL"
string(8) "PROTOCOL"
bool(true)
bool(true)
bool(true)
int(1)

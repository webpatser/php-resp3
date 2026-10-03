--TEST--
Push queue is bounded by maxAggregateCount; overflow is a protocol error cleared by reset()
--EXTENSIONS--
resp3
--FILE--
<?php
$push = ">1\r\n+x\r\n";
$p = new Resp3\Parser(maxAggregateCount: 4, queuePushes: true);
$p->feed(str_repeat($push, 4));
var_dump($p->hasNext());
var_dump($p->hasPush());

$p = new Resp3\Parser(maxAggregateCount: 4, queuePushes: true);
$p->feed(str_repeat($push, 5));
try {
    $p->hasNext();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    var_dump($e->prefix);
    var_dump(str_contains($e->getMessage(), 'push queue limit'));
}
// Pushes queued before the fault stay retrievable
var_dump($p->hasPush());
for ($i = 0; $i < 4; $i++) {
    var_dump($p->nextPush() instanceof Resp3\PushMessage);
}
// Once the queue is empty the latch surfaces
try {
    $p->hasPush();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    var_dump($e->prefix);
    var_dump(str_contains($e->getMessage(), 'error state'));
}
try {
    $p->nextPush();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    var_dump($e->prefix);
    var_dump(str_contains($e->getMessage(), 'error state'));
}

$p->reset();
$p->feed($push);
var_dump($p->hasPush());
var_dump($p->nextPush()->payload);
?>
--EXPECT--
bool(false)
bool(true)
string(8) "PROTOCOL"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(8) "PROTOCOL"
bool(true)
string(8) "PROTOCOL"
bool(true)
bool(true)
array(1) {
  [0]=>
  string(1) "x"
}

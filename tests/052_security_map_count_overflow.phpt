--TEST--
Security: map count near INT64_MAX/2 is rejected before *2 overflow
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
// 4611686018427387905 = INT64_MAX/2 + 1; without the cap, * 2 would wrap negative
$p->feed("%4611686018427387905\r\n");
try {
    $p->hasNext();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    echo "guarded: ", $e->getMessage(), "\n";
}
?>
--EXPECTF--
guarded: RESP3 parse error: aggregate too large%s

--TEST--
Security: bulk length beyond int64_t range is rejected
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("\$9999999999999999999\r\n");                  // 19 nines, overflows int64_t
try {
    $p->hasNext();
    echo "FAIL: should have thrown\n";
} catch (Resp3\RedisException $e) {
    echo "guarded: ", $e->getMessage(), "\n";
}
?>
--EXPECTF--
guarded: RESP3 parse error: %s

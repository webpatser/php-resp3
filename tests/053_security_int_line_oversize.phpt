--TEST--
Security: integer inline line longer than RESP3_MAX_INLINE_LINE is rejected
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
// 100000 nines as an integer line; way above the 64 KiB inline cap
$p->feed(":" . str_repeat("9", 100000) . "\r\n");
try {
    $p->hasNext();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    echo "guarded: ", $e->getMessage(), "\n";
}
?>
--EXPECTF--
guarded: RESP3 parse error: inline line too long%s

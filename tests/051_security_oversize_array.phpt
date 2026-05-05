--TEST--
Security: array element count above max is rejected
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("*9999999999\r\n");                            // 10B elements, well above 1M default
try {
    $p->hasNext();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    echo "guarded: ", $e->getMessage(), "\n";
}
?>
--EXPECTF--
guarded: RESP3 parse error: aggregate too large%s

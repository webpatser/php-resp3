--TEST--
Security: custom maxBulk constructor arg lowers the cap below the default
--EXTENSIONS--
resp3
--FILE--
<?php
// Bulk cap of 100 bytes; 200-byte declared bulk should be rejected
$p = new Resp3\Parser(maxDepth: 100, maxBulk: 100);
$p->feed("\$200\r\n" . str_repeat("x", 200) . "\r\n");
try {
    $p->hasNext();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    echo "guarded: ", $e->getMessage(), "\n";
}

// Aggregate cap of 5; 10-element array should be rejected
$p2 = new Resp3\Parser(maxDepth: 100, maxBulk: 1024, maxAggregateCount: 5);
$p2->feed("*10\r\n");
try {
    $p2->hasNext();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    echo "guarded: ", $e->getMessage(), "\n";
}

// Invalid constructor args are caught
try {
    new Resp3\Parser(maxBulk: 0);
    echo "FAIL\n";
} catch (\ValueError $e) {
    echo "guarded: ", $e->getMessage(), "\n";
}
?>
--EXPECTF--
guarded: RESP3 parse error: bulk too large%s
guarded: RESP3 parse error: aggregate too large%s
guarded: maxBulk must be between 1 and 2147483648 (2 GiB)

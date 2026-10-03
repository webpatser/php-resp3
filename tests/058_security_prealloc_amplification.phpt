--TEST--
Security: declared aggregate size does not preallocate proportional memory
--EXTENSIONS--
resp3
--FILE--
<?php
// PHP allocates array storage on the first insert, so every case feeds the
// first child; without it the preallocation hint would never be exercised.
function growth(string $bytes): int {
    $parsers = [];
    $before = memory_get_usage();
    for ($i = 0; $i < 20; $i++) {
        $p = new Resp3\Parser();
        $p->feed($bytes);
        $p->hasNext();
        $parsers[] = $p;
    }
    return memory_get_usage() - $before;
}

// Top level: first child lands in an array declared with 1M slots.
var_dump(growth("*1000000\r\n:1\r\n") < 1024 * 1024);

// Map: first pair lands in a map declared with 500k pairs.
var_dump(growth("%500000\r\n+k\r\n+v\r\n") < 2 * 1024 * 1024);

// Nested: first child at every one of 100 levels of *1000 inserts into a hinted array.
var_dump(growth(str_repeat("*1000\r\n:1\r\n", 100)) < 8 * 1024 * 1024);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)

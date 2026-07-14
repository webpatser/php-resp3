--TEST--
A lone '-' length field is rejected as empty, RESP2 null lengths still work
--EXTENSIONS--
resp3
--FILE--
<?php
// A length field of just "-" used to slip past the empty-length guard
// (int_neg set, zero digits) and finalize as -0 == 0, delivering an empty
// bulk string or array instead of an error. It must be rejected like an
// empty length.
foreach (["$-\r\n", "*-\r\n"] as $bytes) {
    $p = new Resp3\Parser();
    $p->feed($bytes);
    try {
        $p->hasNext();
        echo "FAIL: should have thrown for ", bin2hex($bytes[0]), "-\n";
    } catch (Resp3\RedisException $e) {
        $isEmptyLen = str_contains($e->getMessage(), 'empty length') ? 'yes' : 'no';
        echo "rejected ", $bytes[0], "- empty-length=$isEmptyLen\n";
    }
}

// Guard against over-tightening: RESP2 null bulk/array keep one digit and
// must still parse as null.
foreach (["$-1\r\n", "*-1\r\n"] as $bytes) {
    $p = new Resp3\Parser();
    $p->feed($bytes);
    var_dump($p->hasNext(), $p->next());
}
?>
--EXPECT--
rejected $- empty-length=yes
rejected *- empty-length=yes
bool(true)
NULL
bool(true)
NULL

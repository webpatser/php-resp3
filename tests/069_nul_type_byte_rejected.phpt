--TEST--
A NUL (0x00) type byte is rejected, not routed to the line parser
--EXTENSIONS--
resp3
--FILE--
<?php
// strchr() also matches a string's terminating NUL, so a 0x00 type byte used
// to slip past the type whitelist and reach the line-parse path. memchr bounds
// the search to the real type characters, so NUL is rejected like any other
// unknown wire byte.
$p = new Resp3\Parser();
$p->feed("\x00\r\n");
try {
    $p->hasNext();
    echo "FAIL: should have thrown for 0x00\n";
} catch (Resp3\RedisException $e) {
    $msg = $e->getMessage();
    $hasHint = str_contains($msg, 'server-to-client') ? 'yes' : 'no';
    echo "guarded 0x00 hint=$hasHint\n";
}
?>
--EXPECT--
guarded 0x00 hint=yes

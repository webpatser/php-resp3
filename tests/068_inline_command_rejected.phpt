--TEST--
Inline commands are rejected with a friendly error
--EXTENSIONS--
resp3
--FILE--
<?php
// Telnet-style inline commands are client-to-server only. Feeding them into
// a server-to-client parser should give a clear error, not a cryptic one.
foreach (["PING\r\n", "GET foo bar\r\n", "\xFF\r\n"] as $bytes) {
    $p = new Resp3\Parser();
    $p->feed($bytes);
    try {
        $p->hasNext();
        echo "FAIL: should have thrown for ", bin2hex($bytes[0]), "\n";
    } catch (Resp3\RedisException $e) {
        $msg = $e->getMessage();
        $hasHint = str_contains($msg, 'server-to-client') ? 'yes' : 'no';
        echo "guarded 0x", bin2hex($bytes[0]), " hint=$hasHint\n";
    }
}
?>
--EXPECT--
guarded 0x50 hint=yes
guarded 0x47 hint=yes
guarded 0xff hint=yes

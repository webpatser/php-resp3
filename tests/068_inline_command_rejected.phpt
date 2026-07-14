--TEST--
Invalid type bytes (inline commands, arbitrary bytes, NUL) are rejected
--EXTENSIONS--
resp3
--FILE--
<?php
// Telnet-style inline commands are client-to-server only, and arbitrary
// bytes are not RESP3 types. Feeding them into a server-to-client parser
// should give a clear error naming the offending byte. The 0x00 entry
// guards a regression where strchr matched the whitelist's terminating NUL
// and let a NUL type byte through to the line parser.
foreach (["PING\r\n", "GET foo bar\r\n", "\xFF\r\n", "\x00\r\n"] as $bytes) {
    $p = new Resp3\Parser();
    $p->feed($bytes);
    try {
        $p->hasNext();
        echo "FAIL: should have thrown for ", bin2hex($bytes[0]), "\n";
    } catch (Resp3\RedisException $e) {
        $msg = $e->getMessage();
        $hasHint = str_contains($msg, 'server-to-client') ? 'yes' : 'no';
        $reportsByte = str_contains($msg, '0x' . bin2hex($bytes[0])) ? 'yes' : 'no';
        echo "guarded 0x", bin2hex($bytes[0]), " hint=$hasHint byte=$reportsByte\n";
    }
}
?>
--EXPECT--
guarded 0x50 hint=yes byte=yes
guarded 0x47 hint=yes byte=yes
guarded 0xff hint=yes byte=yes
guarded 0x00 hint=yes byte=yes

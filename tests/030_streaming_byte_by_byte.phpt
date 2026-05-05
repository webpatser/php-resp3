--TEST--
Streaming: byte-by-byte feed produces identical results to whole-buffer feed
--EXTENSIONS--
resp3
--FILE--
<?php
$bytes = "+OK\r\n"
       . ":42\r\n"
       . "\$5\r\nhello\r\n"
       . "*3\r\n:1\r\n:2\r\n:3\r\n"
       . "*2\r\n*2\r\n:1\r\n:2\r\n*1\r\n+ok\r\n"
       . "\$-1\r\n"
       . "*-1\r\n"
       . "\$0\r\n\r\n"
       . "*0\r\n";

// Whole-buffer baseline
$p1 = new Resp3\Parser();
$p1->feed($bytes);
$expected = [];
while ($p1->hasNext()) {
    $expected[] = $p1->next();
}

// Byte-by-byte feed
$p2 = new Resp3\Parser();
$actual = [];
for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
    $p2->feed($bytes[$i]);
    while ($p2->hasNext()) {
        $actual[] = $p2->next();
    }
}

var_dump($expected == $actual);
var_dump(count($expected));
var_dump(count($actual));
?>
--EXPECT--
bool(true)
int(9)
int(9)

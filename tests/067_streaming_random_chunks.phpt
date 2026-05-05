--TEST--
Streaming: random-sized chunks (32-1024 bytes) match whole-buffer feed
--EXTENSIONS--
resp3
--SKIPIF--
<?php
if (!is_file(__DIR__ . '/fixtures/02_resp3/xreadgroup.bin')) {
    echo "skip xreadgroup.bin fixture not present\n";
}
?>
--FILE--
<?php
// Realistic socket reads chunk in arbitrary sizes, not byte-by-byte. This
// exercises chunk boundaries that land mid-CRLF, mid-length-string, and
// mid-payload, which the byte-by-byte tests already cover from the other
// extreme. Together they bracket the realistic distribution.
mt_srand(42);                              // determinism

$bytes = file_get_contents(__DIR__ . '/fixtures/02_resp3/xreadgroup.bin');

// Whole-buffer baseline
$p1 = new Resp3\Parser();
$p1->feed($bytes);
$expected = [];
while ($p1->hasNext()) $expected[] = $p1->next();

// Random-chunk feed
$p2 = new Resp3\Parser();
$actual = [];
$pos = 0;
$len = strlen($bytes);
while ($pos < $len) {
    $chunk = mt_rand(32, 1024);
    if ($pos + $chunk > $len) $chunk = $len - $pos;
    $p2->feed(substr($bytes, $pos, $chunk));
    while ($p2->hasNext()) $actual[] = $p2->next();
    $pos += $chunk;
}

var_dump(md5(serialize($expected)) === md5(serialize($actual)));
var_dump(count($expected) === count($actual));
var_dump(count($expected) > 0);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)

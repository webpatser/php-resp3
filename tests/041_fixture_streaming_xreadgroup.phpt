--TEST--
Fixtures: byte-by-byte streaming of real XREADGROUP fixture matches whole-buffer
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
$bytes = file_get_contents(__DIR__ . '/fixtures/02_resp3/xreadgroup.bin');

$p1 = new Resp3\Parser();
$p1->feed($bytes);
$expected = [];
while ($p1->hasNext()) {
    $expected[] = $p1->next();
}

$p2 = new Resp3\Parser();
$actual = [];
for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
    $p2->feed($bytes[$i]);
    while ($p2->hasNext()) {
        $actual[] = $p2->next();
    }
}

var_dump($expected == $actual);
var_dump(count($expected) > 0);
var_dump(count($expected) === count($actual));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)

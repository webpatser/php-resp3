--TEST--
Streaming: byte-by-byte feed identical for full RESP3 type coverage
--EXTENSIONS--
resp3
--FILE--
<?php
$bytes =
    "_\r\n" .
    "#t\r\n" .
    "#f\r\n" .
    ",1.5\r\n" .
    ",inf\r\n" .
    "(123456789012345678901234567890\r\n" .
    "=15\r\ntxt:Some string\r\n" .
    "!21\r\nSYNTAX invalid syntax\r\n" .
    "~3\r\n+a\r\n+b\r\n+c\r\n" .
    ">2\r\n+pubsub\r\n+message\r\n" .
    "%2\r\n+x\r\n:1\r\n+y\r\n:2\r\n";

$collect = function (Resp3\Parser $p): array {
    $out = [];
    while ($p->hasNext()) {
        $m = $p->next();
        if ($m instanceof Resp3\VerbatimString) {
            $out[] = ['VS', $m->type, $m->value];
        } elseif ($m instanceof Resp3\PushMessage) {
            $out[] = ['PM', $m->payload];
        } elseif ($m instanceof Resp3\RedisException) {
            $out[] = ['RE', $m->getMessage()];
        } else {
            $out[] = $m;
        }
    }
    return $out;
};

$p1 = new Resp3\Parser();
$p1->feed($bytes);
$expected = $collect($p1);

$p2 = new Resp3\Parser();
$actual = [];
for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
    $p2->feed($bytes[$i]);
    if ($p2->hasNext()) {
        $actual = array_merge($actual, $collect($p2));
    }
}

var_dump($expected == $actual);
var_dump(count($expected));
?>
--EXPECT--
bool(true)
int(11)

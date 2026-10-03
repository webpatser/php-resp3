--TEST--
Malformed integer and double lines are rejected; valid edge forms parse
--EXTENSIONS--
resp3
--FILE--
<?php
$bad = [
    ': 5', ':+5', ':0x1f', ':5 ', ',0x1p3', ', 1.5', ',infinity', ',1e', ',.',
];
foreach ($bad as $line) {
    $p = new Resp3\Parser();
    $p->feed($line . "\r\n");
    try {
        $v = $p->next();
        echo "FAIL accepted ", json_encode($line), "\n";
    } catch (Resp3\RedisException $e) {
        echo "rejected ", json_encode($line), "\n";
    }
}

$p = new Resp3\Parser();
$p->feed(",-nan\r\n");
var_dump(is_nan($p->next()));
$p->feed(",1e2\r\n");
var_dump($p->next());
$p->feed(":-0\r\n");
var_dump($p->next());
?>
--EXPECT--
rejected ": 5"
rejected ":+5"
rejected ":0x1f"
rejected ":5 "
rejected ",0x1p3"
rejected ", 1.5"
rejected ",infinity"
rejected ",1e"
rejected ",."
bool(true)
float(100)
int(0)

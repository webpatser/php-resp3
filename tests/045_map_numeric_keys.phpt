--TEST--
Map keys: numeric strings become integer keys, doubles become string keys, aggregates throw
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("%2\r\n:1\r\n+a\r\n\$3\r\n123\r\n+b\r\n");
$m = $p->next();
var_dump($m[1] === 'a');
var_dump($m[123] === 'b');
var_dump(array_keys($m) === [1, 123]);

$p = new Resp3\Parser();
$p->feed("%1\r\n,1.5\r\n+x\r\n");
$m = $p->next();
var_dump(array_keys($m) === ['1.5']);
var_dump($m['1.5']);

$p = new Resp3\Parser();
$p->feed("%1\r\n*1\r\n:1\r\n+x\r\n");
try {
    $p->next();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    echo "guarded\n";
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
string(1) "x"
guarded

--TEST--
Verbatim strings are wrapped at any depth; null-length verbatim and blob error are protocol errors
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("*1\r\n=7\r\ntxt:abc\r\n");
$r = $p->next();
var_dump($r[0] instanceof Resp3\VerbatimString);
var_dump($r[0]->type, $r[0]->value);

foreach (["=-1\r\n", "!-1\r\n"] as $bytes) {
    $p = new Resp3\Parser();
    $p->feed($bytes);
    try {
        $p->next();
        echo "FAIL\n";
    } catch (Resp3\RedisException $e) {
        var_dump($e->prefix);
    }
}
?>
--EXPECT--
bool(true)
string(3) "txt"
string(3) "abc"
string(8) "PROTOCOL"
string(8) "PROTOCOL"

--TEST--
Security: re-calling __construct on an existing parser throws ValueError
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
try {
    $p->__construct();
    echo "FAIL: should have thrown\n";
} catch (\ValueError $e) {
    echo "guarded: ", $e->getMessage(), "\n";
}
// reset() is the supported way to recycle an instance
$p->reset();
$p->feed("+OK\r\n");
var_dump($p->next());
?>
--EXPECT--
guarded: Resp3\Parser is already constructed; create a new instance or call reset()
string(2) "OK"

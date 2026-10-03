--TEST--
Re-calling __construct discards buffered state and reinitialises with the new caps
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed("*2\r\n:1\r\n");          // partial aggregate buffered
$p->__construct();
var_dump($p->hasNext());           // nothing left from the old input
$p->feed("+OK\r\n");
var_dump($p->next());              // not swallowed into the old aggregate

// New caps apply after re-construction
$p->__construct(maxBulk: 10);
$p->feed("\$20\r\n");
try {
    $p->hasNext();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    echo "guarded\n";
}

// Latched error is cleared by re-construction
$p->__construct();
$p->feed(":5\r\n");
var_dump($p->next());
?>
--EXPECT--
bool(false)
string(2) "OK"
guarded
int(5)

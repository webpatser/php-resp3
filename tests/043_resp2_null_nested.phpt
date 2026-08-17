--TEST--
RESP2 nulls nested inside aggregates ($-1 / *-1 as array elements)
--EXTENSIONS--
resp3
--FILE--
<?php
// Regression: nested RESP2 nulls left the state machine in LEN_LF, so the
// next element's type byte was misread as a missing LF ("expected LF after
// CR in length"). Top-level nulls always worked; only nulls inside an
// aggregate hit the bug. The first fixture is the XPENDING summary reply
// for an empty pending list, exactly as seen on the wire from a RESP2
// connection.
$fixtures = [
    'xpending summary' => "*4\r\n:0\r\n\$-1\r\n\$-1\r\n*-1\r\n",
    'mget with misses' => "*3\r\n\$-1\r\n\$5\r\nhello\r\n\$-1\r\n",
    'null array nested' => "*2\r\n*-1\r\n:7\r\n",
    'deeply nested' => "*2\r\n*2\r\n\$-1\r\n*-1\r\n:1\r\n",
];

foreach ($fixtures as $label => $bytes) {
    $p = new Resp3\Parser();
    $p->feed($bytes);
    $values = [];
    while ($p->hasNext()) {
        $values[] = $p->next();
    }
    echo $label, ': ', json_encode($values), "\n";
}

// Byte-by-byte feed must agree with whole-buffer feed.
$bytes = implode('', $fixtures);
$p = new Resp3\Parser();
$streamed = [];
for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
    $p->feed($bytes[$i]);
    while ($p->hasNext()) {
        $streamed[] = $p->next();
    }
}
echo 'streamed: ', json_encode($streamed), "\n";
?>
--EXPECT--
xpending summary: [[0,null,null,null]]
mget with misses: [[null,"hello",null]]
null array nested: [[null,7]]
deeply nested: [[[null,null],1]]
streamed: [[0,null,null,null],[null,"hello",null],[null,7],[[null,null],1]]

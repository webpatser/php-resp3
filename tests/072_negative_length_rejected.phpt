--TEST--
Strict lengths: only $-1 and *-1 are null; other negatives, -0 and leading zeros are rejected
--EXTENSIONS--
resp3
--FILE--
<?php
$bad = ['$-2', '*-5', '%-1', '~-1', '>-1', '!-1', '=-1', '|-1', '$-0', '$007', '*01'];
foreach ($bad as $line) {
    $p = new Resp3\Parser();
    $p->feed($line . "\r\n");
    try {
        $v = $p->next();
        echo "FAIL ", $line, " returned ", var_export($v, true), "\n";
    } catch (Resp3\RedisException $e) {
        echo "rejected ", $line, "\n";
    }
}
foreach (['$-1', '*-1'] as $line) {
    $p = new Resp3\Parser();
    $p->feed($line . "\r\n");
    var_dump($p->next());
}
$p = new Resp3\Parser();
$p->feed("\$0\r\n\r\n*0\r\n");
var_dump($p->next());
var_dump($p->next());
?>
--EXPECT--
rejected $-2
rejected *-5
rejected %-1
rejected ~-1
rejected >-1
rejected !-1
rejected =-1
rejected |-1
rejected $-0
rejected $007
rejected *01
NULL
NULL
string(0) ""
array(0) {
}

--TEST--
Security: maxDepth limits nesting; ceiling of 10000 is enforced
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser(maxDepth: 3);
$p->feed("*1\r\n*1\r\n*1\r\n:1\r\n");
var_dump($p->next());

$p = new Resp3\Parser(maxDepth: 3);
$p->feed("*1\r\n*1\r\n*1\r\n*1\r\n:1\r\n");
try {
    $p->hasNext();
    echo "FAIL\n";
} catch (Resp3\RedisException $e) {
    var_dump(str_contains($e->getMessage(), 'max depth'));
}

try {
    new Resp3\Parser(maxDepth: 10001);
    echo "FAIL\n";
} catch (\ValueError $e) {
    echo "guarded\n";
}
$p = new Resp3\Parser(maxDepth: 10000);
echo "ok\n";
?>
--EXPECT--
array(1) {
  [0]=>
  array(1) {
    [0]=>
    array(1) {
      [0]=>
      int(1)
    }
  }
}
bool(true)
guarded
ok

--TEST--
RESP3: double (,) including inf, -inf, nan
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed(",1.5\r\n,inf\r\n,-inf\r\n,nan\r\n,0\r\n,-3.14\r\n");
var_dump($p->next());
$inf = $p->next(); var_dump($inf === INF);
$ninf = $p->next(); var_dump($ninf === -INF);
$nan = $p->next(); var_dump(is_nan($nan));
var_dump($p->next());
var_dump($p->next());
?>
--EXPECT--
float(1.5)
bool(true)
bool(true)
bool(true)
float(0)
float(-3.14)

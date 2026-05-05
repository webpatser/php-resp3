--TEST--
Boundary: length digit count and INT64 edges
--EXTENSIONS--
resp3
--FILE--
<?php
// Integer line at INT64_MAX is accepted (line goes through finalize_line, not
// the length parser). 19 digits, fits in int64.
$p = new Resp3\Parser();
$p->feed(":9223372036854775807\r\n");
var_dump($p->next());

// 19 digits but the value fits in int64 yet exceeds the maxBulk default
// (1e18 vs 512 MiB). The length parser accepts it, finalize_length rejects.
$p = new Resp3\Parser();
$p->feed("\$1000000000000000000\r\n");
try { $p->hasNext(); echo "FAIL\n"; }
catch (Resp3\RedisException $e) { echo "case A: ", $e->getMessage(), "\n"; }

// 19 digits that overflow int64 (9999999999999999999 > INT64_MAX). Caught
// by the multiply-add overflow guard before the value lands.
$p = new Resp3\Parser();
$p->feed("\$9999999999999999999\r\n");
try { $p->hasNext(); echo "FAIL\n"; }
catch (Resp3\RedisException $e) { echo "case B: ", $e->getMessage(), "\n"; }

// 20 digits with leading zeros. The overflow guard does not fire
// (int_acc stays tiny), so the digit-count cap is what catches it.
$p = new Resp3\Parser();
$p->feed("\$00000000000000000001\r\n");
try { $p->hasNext(); echo "FAIL\n"; }
catch (Resp3\RedisException $e) { echo "case C: ", $e->getMessage(), "\n"; }
?>
--EXPECTF--
int(9223372036854775807)
case A: RESP3 parse error: bulk too large%s
case B: RESP3 parse error: length out of range
case C: RESP3 parse error: length has too many digits%s

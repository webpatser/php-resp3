--TEST--
Fixtures: handcrafted edges (NaN, big number, deep nesting, etc.)
--EXTENSIONS--
resp3
--SKIPIF--
<?php
if (!is_dir(__DIR__ . '/fixtures/handcrafted')) {
    echo "skip fixtures/handcrafted not present\n";
}
?>
--FILE--
<?php
$dir = __DIR__ . '/fixtures/handcrafted';

function parse_one(string $path) {
    $p = new Resp3\Parser(200);
    $p->feed(file_get_contents($path));
    $msgs = [];
    while ($p->hasNext()) $msgs[] = $p->next();
    return $msgs;
}

// Spot-check the wire-edge values that Redis won't naturally emit.
$null = parse_one("$dir/null.bin"); var_dump($null === [null]);
$nan  = parse_one("$dir/double_nan.bin"); var_dump(is_nan($nan[0]));
$inf  = parse_one("$dir/double_inf.bin"); var_dump($inf[0] === INF);
$ninf = parse_one("$dir/double_neg_inf.bin"); var_dump($ninf[0] === -INF);
$big  = parse_one("$dir/big_number.bin"); var_dump($big[0]);
$emap = parse_one("$dir/empty_map.bin"); var_dump($emap === [[]]);
$eset = parse_one("$dir/empty_set.bin"); var_dump($eset === [[]]);
$verb = parse_one("$dir/verbatim.bin"); var_dump($verb[0] instanceof Resp3\VerbatimString, $verb[0]->type, $verb[0]->value);
$push = parse_one("$dir/push.bin"); var_dump($push[0] instanceof Resp3\PushMessage, $push[0]->payload);
$blob = parse_one("$dir/blob_error.bin"); var_dump($blob[0] instanceof Resp3\RedisException, $blob[0]->getMessage());

// Deep nesting: 99 levels of *1 wrappers around a single +leaf
$deep = parse_one("$dir/deep_nesting_99.bin");
$cur = $deep[0];
$depth = 0;
while (is_array($cur) && count($cur) === 1) { $cur = $cur[0]; $depth++; }
var_dump($depth);
var_dump($cur);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
string(43) "3492890328409238509324850943850943825024385"
bool(true)
bool(true)
bool(true)
string(3) "txt"
string(11) "Some string"
bool(true)
array(2) {
  [0]=>
  string(6) "pubsub"
  [1]=>
  string(7) "message"
}
bool(true)
string(21) "SYNTAX invalid syntax"
int(99)
string(4) "leaf"

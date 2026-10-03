--TEST--
Push queue mode: pushes are diverted to hasPush()/nextPush(); default mode unchanged
--EXTENSIONS--
resp3
--FILE--
<?php
$bytes = ":1\r\n>2\r\n+message\r\n+hi\r\n:2\r\n";

function drain(Resp3\Parser $p, array &$vals, array &$pushes): void {
    while ($p->hasNext()) $vals[] = $p->next();
    while ($p->hasPush()) $pushes[] = $p->nextPush();
}

// Queue mode, whole buffer
$p = new Resp3\Parser(queuePushes: true);
$p->feed($bytes);
$vals = []; $pushes = [];
drain($p, $vals, $pushes);
var_dump($vals);
var_dump(count($pushes), $pushes[0] instanceof Resp3\PushMessage, $pushes[0]->payload);
var_dump($p->hasPush());
var_dump($p->nextPush());

// Queue mode, byte by byte
$p = new Resp3\Parser(queuePushes: true);
$vals = []; $pushes = [];
for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
    $p->feed($bytes[$i]);
    drain($p, $vals, $pushes);
}
var_dump($vals);
var_dump(count($pushes), $pushes[0]->payload);

// Push only, nextPush() finds it without a regular reply
$p = new Resp3\Parser(queuePushes: true);
$p->feed(">1\r\n+solo\r\n");
var_dump($p->hasPush());
var_dump($p->nextPush()->payload);
var_dump($p->nextPush());

// Default mode
$p = new Resp3\Parser();
$p->feed($bytes);
$out = [];
while ($p->hasNext()) $out[] = $p->next();
var_dump($out[0], $out[1] instanceof Resp3\PushMessage, $out[2], count($out));
var_dump($p->hasPush());
?>
--EXPECT--
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
int(1)
bool(true)
array(2) {
  [0]=>
  string(7) "message"
  [1]=>
  string(2) "hi"
}
bool(false)
NULL
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
int(1)
array(2) {
  [0]=>
  string(7) "message"
  [1]=>
  string(2) "hi"
}
bool(true)
array(1) {
  [0]=>
  string(4) "solo"
}
NULL
int(1)
bool(true)
int(2)
int(3)
bool(false)

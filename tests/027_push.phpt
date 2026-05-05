--TEST--
RESP3: push (>) wraps payload in Resp3\PushMessage
--EXTENSIONS--
resp3
--FILE--
<?php
$p = new Resp3\Parser();
$p->feed(">2\r\n+pubsub\r\n+message\r\n");
$pm = $p->next();
var_dump($pm instanceof Resp3\PushMessage);
var_dump($pm->payload);
?>
--EXPECT--
bool(true)
array(2) {
  [0]=>
  string(6) "pubsub"
  [1]=>
  string(7) "message"
}

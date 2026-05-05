--TEST--
Real shape: pubsub message push frame
--EXTENSIONS--
resp3
--FILE--
<?php
// Server emits this shape after HELLO 3 + SUBSCRIBE ch + PUBLISH ch hello:
//   >3 (push)
//     +message
//     +ch
//     $5 hello
$p = new Resp3\Parser();
$p->feed(">3\r\n+message\r\n+ch\r\n\$5\r\nhello\r\n");
$pm = $p->next();

var_dump($pm instanceof Resp3\PushMessage);
var_dump(count($pm->payload));
var_dump($pm->payload[0]);
var_dump($pm->payload[1]);
var_dump($pm->payload[2]);
?>
--EXPECT--
bool(true)
int(3)
string(7) "message"
string(2) "ch"
string(5) "hello"

--TEST--
Real shape: HELLO 3 reply parses to a map with the documented keys
--EXTENSIONS--
resp3
--SKIPIF--
<?php
if (!is_file(__DIR__ . '/fixtures/02_resp3/hello.bin')) {
    echo "skip hello.bin fixture not present\n";
}
?>
--FILE--
<?php
$bytes = file_get_contents(__DIR__ . '/fixtures/02_resp3/hello.bin');

$p = new Resp3\Parser();
$p->feed($bytes);

// hello.bin captures one full session: HELLO 3 + a follow-up command.
// We only care about the first message: the HELLO 3 reply (a map).
$reply = $p->next();

var_dump(is_array($reply));
// The HELLO reply is documented to include at least these keys
foreach (['server', 'version', 'proto', 'id', 'mode', 'role', 'modules'] as $k) {
    var_dump(array_key_exists($k, $reply));
}
// proto is the negotiated RESP version, must be int 3 after HELLO 3
var_dump($reply['proto']);
var_dump(is_int($reply['id']));
var_dump(is_array($reply['modules']));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(3)
bool(true)
bool(true)

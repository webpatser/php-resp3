--TEST--
Fixtures: parse real Valkey RESP3 responses captured with tools/capture_fixtures.php
--EXTENSIONS--
resp3
--SKIPIF--
<?php
if (!is_dir(__DIR__ . '/fixtures/02_resp3')) {
    echo "skip fixtures/02_resp3 not present — run tools/capture_fixtures.php\n";
}
?>
--FILE--
<?php
$dir = __DIR__ . '/fixtures/02_resp3';
$files = glob("$dir/*.bin");
sort($files);

foreach ($files as $f) {
    $bytes = file_get_contents($f);
    $p = new Resp3\Parser();
    $p->feed($bytes);
    $messages = [];
    while ($p->hasNext()) {
        $messages[] = $p->next();
    }
    printf("%-20s  %d msgs\n", basename($f), count($messages));
}
?>
--EXPECTF--
client_info.bin       %d msgs
error.bin             %d msgs
get_null.bin          %d msgs
get_string.bin        %d msgs
hello.bin             %d msgs
hgetall.bin           %d msgs
incr.bin              %d msgs
lrange.bin            %d msgs
set_ok.bin            %d msgs
smembers.bin          %d msgs
xreadgroup.bin        %d msgs

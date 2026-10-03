--TEST--
Fuzz: single-byte mutations of every fixture never crash and only throw RedisException
--EXTENSIONS--
resp3
--FILE--
<?php
mt_srand(42);

function run(Resp3\Parser $p, string $bytes, bool $bytewise): void {
    $chunks = $bytewise ? str_split($bytes) : [$bytes];
    foreach ($chunks as $c) {
        $p->feed($c);
        while ($p->hasNext()) {
            $p->next();
        }
        while ($p->hasPush()) {
            $p->nextPush();
        }
    }
}

$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/fixtures', FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->getExtension() === 'bin') $files[] = $f->getPathname();
}
sort($files);

$unexpected = 0;
$runs = 0;
foreach ($files as $file) {
    $orig = file_get_contents($file);
    $len = strlen($orig);
    if ($len === 0) continue;
    for ($m = 0; $m < 50; $m++) {
        $bytes = $orig;
        $bytes[mt_rand(0, $len - 1)] = chr(mt_rand(0, 255));
        foreach ([false, true] as $bytewise) {
            foreach ([false, true] as $queue) {
                $p = new Resp3\Parser(queuePushes: $queue);
                $runs++;
                try {
                    run($p, $bytes, $bytewise);
                } catch (Resp3\RedisException $e) {
                    // expected for malformed input
                } catch (\Throwable $e) {
                    $unexpected++;
                    echo get_class($e), ': ', $e->getMessage(), ' in ', basename($file), "\n";
                }
                // reset() must restore a usable parser
                $p->reset();
                $p->feed(":7\r\n");
                if (!$p->hasNext() || $p->next() !== 7) {
                    $unexpected++;
                    echo "reset failed for ", basename($file), "\n";
                }
            }
        }
    }
}
var_dump(count($files) > 0);
var_dump($runs > 0);
var_dump($unexpected);
?>
--EXPECT--
bool(true)
bool(true)
int(0)

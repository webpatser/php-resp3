<?php declare(strict_types=1);
/**
 * Scenario 2: allocation pressure / GC stress.
 *
 * Hand each parser a 100-message batch on every iteration so the resulting
 * PHP arrays accumulate and exercise the allocator. Each iteration creates
 * a fresh parser instance; we explicitly enable GC tracking.
 *
 *   php -d extension=./modules/resp3.so bench/02_allocation_pressure.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Resp3\Bench\Harness;

if (!extension_loaded('resp3')) {
    fwrite(STDERR, "ext-resp3 not loaded; run with -d extension=./modules/resp3.so\n");
    exit(1);
}

// Big batch: 100 XREADGROUP-shaped messages stitched together. Each message
// is ~14KB. Forces the parser to allocate ~5000 sub-arrays per iteration.
function build_batch(int $messages = 20, int $entries = 25, int $fields = 4): string
{
    $one = '*1' . "\r\n*2\r\n";
    $key = 'stream:bench';
    $one .= '$' . strlen($key) . "\r\n" . $key . "\r\n";
    $one .= '*' . $entries . "\r\n";
    for ($e = 0; $e < $entries; $e++) {
        $one .= "*2\r\n";
        $id = sprintf('%d-%d', 1700000000000 + $e, $e);
        $one .= '$' . strlen($id) . "\r\n" . $id . "\r\n";
        $one .= '*' . ($fields * 2) . "\r\n";
        for ($f = 0; $f < $fields; $f++) {
            $name  = "field{$f}";
            $value = "payload-value-of-some-reasonable-length-{$e}-{$f}";
            $one .= '$' . strlen($name)  . "\r\n" . $name  . "\r\n";
            $one .= '$' . strlen($value) . "\r\n" . $value . "\r\n";
        }
    }
    return str_repeat($one, $messages);
}

$batch        = build_batch();
$bytesPerIter = strlen($batch);
echo "batch: " . number_format($bytesPerIter) . " bytes (20 messages, 25 entries, 4 fields)\n\n";

gc_enable();

$harness = new Harness(
    scenario: 'Allocation pressure (20-message XREADGROUP batch per iteration)',
    warmupSeconds: 1.0,
    measureSeconds: 3.0,
    runs: 5,
);

$harness->measure('php-resp3 (C)', function () use ($batch, $bytesPerIter): int {
    $p   = new \Resp3\Parser();
    $sum = 0;
    $p->feed($batch);
    while ($p->hasNext()) {
        $msg = $p->next();
        // Touch the structure so dead-store elimination doesn't kick in.
        $sum += is_array($msg) ? count($msg) : 1;
    }
    return $bytesPerIter;
});

$harness->measure('Fledge RespParser (PHP)', function () use ($batch, $bytesPerIter): int {
    $sum = 0;
    $parser = new \Fledge\Async\Redis\Protocol\RespParser(static function ($r) use (&$sum): void {
        $v   = $r->unwrap();
        $sum += is_array($v) ? count($v) : 1;
    });
    $parser->push($batch);
    return $bytesPerIter;
});

$harness->measure('Amp\\Redis RespParser (PHP)', function () use ($batch, $bytesPerIter): int {
    $sum = 0;
    $parser = new \Amp\Redis\Protocol\RespParser(static function ($r) use (&$sum): void {
        $v   = $r->unwrap();
        $sum += is_array($v) ? count($v) : 1;
    });
    $parser->push($batch);
    return $bytesPerIter;
});

echo $harness->report();

$out = __DIR__ . '/results/02_allocation_pressure.md';
file_put_contents($out, $harness->report());
echo "\nWrote $out\n";

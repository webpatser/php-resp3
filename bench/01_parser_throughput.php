<?php declare(strict_types=1);
/**
 * Scenario 1: pure parsing throughput.
 *
 * Pre-load a buffer of N copies of a captured XREADGROUP fixture, then feed
 * the entire buffer to each parser in one shot per iteration. No socket I/O,
 * no Fibers, just bytes -> PHP values.
 *
 *   php -d extension=./modules/resp3.so bench/01_parser_throughput.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Resp3\Bench\Harness;

if (!extension_loaded('resp3')) {
    fwrite(STDERR, "ext-resp3 not loaded; run with -d extension=./modules/resp3.so\n");
    exit(1);
}

// Synthetic RESP2 XREADGROUP-style payload. Captured fixtures start with a
// HELLO 3 RESP3 map (`%`) which the pure-PHP RespParsers don't accept; we
// need a RESP2-only payload for an apples-to-apples comparison.
function synthetic_xreadgroup(int $streams = 1, int $entries = 50, int $fieldsPerEntry = 4): string
{
    $out = '*' . $streams . "\r\n";
    for ($s = 0; $s < $streams; $s++) {
        $out .= "*2\r\n";                                          // [stream-key, entries]
        $key = "stream:{$s}";
        $out .= '$' . strlen($key) . "\r\n" . $key . "\r\n";
        $out .= '*' . $entries . "\r\n";
        for ($e = 0; $e < $entries; $e++) {
            $out .= "*2\r\n";                                      // [id, fields]
            $id = sprintf('%d-%d', 1700000000000 + $e, $e);
            $out .= '$' . strlen($id) . "\r\n" . $id . "\r\n";
            $out .= '*' . ($fieldsPerEntry * 2) . "\r\n";
            for ($f = 0; $f < $fieldsPerEntry; $f++) {
                $name  = "field{$f}";
                $value = "payload-value-of-some-reasonable-length-{$e}-{$f}";
                $out .= '$' . strlen($name)  . "\r\n" . $name  . "\r\n";
                $out .= '$' . strlen($value) . "\r\n" . $value . "\r\n";
            }
        }
    }
    return $out;
}

// One small XREADGROUP-shaped reply per iteration. Iteration count is what
// matters for the ratio; each parser sees the same buffer.
$fixture      = synthetic_xreadgroup(streams: 1, entries: 10, fieldsPerEntry: 3);
$bytesPerIter = strlen($fixture);

echo "fixture: synthetic XREADGROUP (1 stream, 10 entries, 3 fields)\n";
echo "         $bytesPerIter bytes per iteration\n\n";

$buffer = $fixture;

$harness = new Harness(
    scenario: 'Parser throughput (XREADGROUP-shaped, 1×10×3, one feed per iteration)',
    warmupSeconds: 1.0,
    measureSeconds: 3.0,
    runs: 5,
);

// php-resp3 (C parser via ext-resp3)
$harness->measure('php-resp3 (C)', function () use ($buffer, $bytesPerIter): int {
    $p = new \Resp3\Parser();
    $p->feed($buffer);
    while ($p->hasNext()) {
        $p->next();
    }
    return $bytesPerIter;
});

// Fledge pure-PHP RespParser (RESP2 only; xreadgroup fixture is RESP2-compatible)
$harness->measure('Fledge RespParser (PHP)', function () use ($buffer, $bytesPerIter): int {
    $count = 0;
    $parser = new \Fledge\Async\Redis\Protocol\RespParser(static function ($r) use (&$count): void {
        $count++;
    });
    $parser->push($buffer);
    return $bytesPerIter;
});

// amphp/redis pure-PHP RespParser (also RESP2-only)
$harness->measure('Amp\\Redis RespParser (PHP)', function () use ($buffer, $bytesPerIter): int {
    $count = 0;
    $parser = new \Amp\Redis\Protocol\RespParser(static function ($r) use (&$count): void {
        $count++;
    });
    $parser->push($buffer);
    return $bytesPerIter;
});

echo $harness->report();

$out = __DIR__ . '/results/01_parser_throughput.md';
file_put_contents($out, $harness->report());
echo "\nWrote $out\n";

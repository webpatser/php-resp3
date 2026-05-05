<?php declare(strict_types=1);
/**
 * Scenario 3a: end-to-end Fledge over a real Redis socket.
 *
 * Pre-populate a stream with N entries, then loop XREADGROUP COUNT=BATCH
 * until the stream is drained. Measure jobs/sec for both stock Fledge and
 * Fledge-with-FledgeAdapter (ext-resp3 under the hood).
 *
 *   php -d extension=./modules/resp3.so bench/03_e2e_fledge.php
 *
 * Requires Redis/Valkey on 127.0.0.1:6379.
 */

require __DIR__ . '/../vendor/autoload.php';

use Fledge\Async\Redis\Protocol\ParserFactory;
use Resp3\Adapter\FledgeAdapter;

use function Fledge\Async\Redis\createRedisClient;

if (!extension_loaded('resp3')) {
    fwrite(STDERR, "ext-resp3 not loaded; run with -d extension=./modules/resp3.so\n");
    exit(1);
}

const STREAM_KEY = 'r3:bench:stream';
const GROUP      = 'r3:bench:group';
const CONSUMER   = 'c1';
const TOTAL      = 50_000;
const BATCH      = 100;
const RUNS       = 5;

function prep_stream(\Fledge\Async\Redis\RedisClient $client): void
{
    $client->execute('DEL', STREAM_KEY);
    try { $client->execute('XGROUP', 'DESTROY', STREAM_KEY, GROUP); } catch (\Throwable) {}

    // Pipeline-style XADDs in chunks to keep the prep fast.
    for ($i = 0; $i < TOTAL; $i++) {
        $client->execute('XADD', STREAM_KEY, '*',
            'f1', 'value-1-' . $i,
            'f2', 'value-2-' . $i,
            'f3', 'value-3-' . $i,
            'f4', 'value-4-' . $i,
        );
    }
    $client->execute('XGROUP', 'CREATE', STREAM_KEY, GROUP, '0');
}

function drain(\Fledge\Async\Redis\RedisClient $client): int
{
    $consumed = 0;
    while (true) {
        $reply = $client->execute('XREADGROUP', 'GROUP', GROUP, CONSUMER, 'COUNT', (string) BATCH, 'STREAMS', STREAM_KEY, '>');
        if (!is_array($reply) || $reply === []) {
            return $consumed;
        }
        // reply: [[stream-key, [[id, [k,v,k,v,...]], ...]]]
        $entries = $reply[0][1] ?? [];
        $consumed += count($entries);
        if ($consumed >= TOTAL) return $consumed;
    }
}

function bench(string $label, \Closure $clientFactory): array
{
    $samples = [];
    for ($r = 0; $r < RUNS; $r++) {
        $client = $clientFactory();
        prep_stream($client);

        // Reset the consumer group cursor so we can replay.
        $client->execute('XGROUP', 'DESTROY', STREAM_KEY, GROUP);
        $client->execute('XGROUP', 'CREATE', STREAM_KEY, GROUP, '0');

        $start = microtime(true);
        $consumed = drain($client);
        $elapsed = microtime(true) - $start;

        $samples[] = ['consumed' => $consumed, 'seconds' => $elapsed];
        printf("  [%s run %d] %d jobs in %.3fs = %.0f jobs/s\n", $label, $r + 1, $consumed, $elapsed, $consumed / $elapsed);
    }
    usort($samples, fn ($a, $b) => ($a['consumed'] / $a['seconds']) <=> ($b['consumed'] / $b['seconds']));
    return $samples[(int) floor(count($samples) / 2)];
}

echo "Scenario 3a: end-to-end Fledge XREADGROUP loop\n";
echo "             " . TOTAL . " jobs total, COUNT=" . BATCH . ", " . RUNS . " runs each, median reported\n\n";

echo "Stock Fledge (pure-PHP RespParser):\n";
ParserFactory::set(null);
$stock = bench('stock', static fn () => createRedisClient('redis://127.0.0.1:6379'));

echo "\nFledge with FledgeAdapter (ext-resp3):\n";
ParserFactory::set(static fn (\Closure $push) => new FledgeAdapter($push));
$swapped = bench('swap', static fn () => createRedisClient('redis://127.0.0.1:6379'));

ParserFactory::set(null);

$stockRate   = $stock['consumed'] / $stock['seconds'];
$swappedRate = $swapped['consumed'] / $swapped['seconds'];
$delta       = (($swappedRate - $stockRate) / $stockRate) * 100;

$report = "## E2E Fledge XREADGROUP loop\n\n";
$report .= '_' . TOTAL . ' jobs, COUNT=' . BATCH . ', ' . RUNS . " runs, median reported_\n\n";
$report .= "| variant | jobs/s | seconds |\n";
$report .= "| --- | ---: | ---: |\n";
$report .= sprintf("| stock Fledge (pure-PHP) | %s | %.3f |\n", number_format($stockRate, 0), $stock['seconds']);
$report .= sprintf("| Fledge + FledgeAdapter (C) | %s | %.3f |\n", number_format($swappedRate, 0), $swapped['seconds']);
$report .= sprintf("\n**Delta**: %+.1f%%\n", $delta);

echo "\n";
echo $report;

$out = __DIR__ . '/results/03_e2e_fledge.md';
file_put_contents($out, $report);
echo "\nWrote $out\n";

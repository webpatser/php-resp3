<?php declare(strict_types=1);
/**
 * Scenario 3b: end-to-end amphp/redis over a real Redis socket.
 *
 * Mirror of 03_e2e_fledge.php but via Amp\Redis. Stock variant uses upstream
 * SocketRedisConnector; swapped variant uses our AmpRedisConnector + AmpRedisConnection
 * which routes parsing through ext-resp3.
 *
 *   php -d extension=./modules/resp3.so bench/03_e2e_amphp.php
 *
 * Requires Redis/Valkey on 127.0.0.1:6379.
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Redis\Connection\SocketRedisConnector;
use Amp\Redis\RedisConfig;
use Amp\Socket\ConnectContext;
use Resp3\Adapter\AmpRedisConnector;

use function Amp\Redis\createRedisClient;

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

function prep_stream(\Amp\Redis\RedisClient $client): void
{
    $client->execute('DEL', STREAM_KEY);
    try { $client->execute('XGROUP', 'DESTROY', STREAM_KEY, GROUP); } catch (\Throwable) {}

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

function drain(\Amp\Redis\RedisClient $client): int
{
    $consumed = 0;
    while (true) {
        $reply = $client->execute('XREADGROUP', 'GROUP', GROUP, CONSUMER, 'COUNT', (string) BATCH, 'STREAMS', STREAM_KEY, '>');
        if (!is_array($reply) || $reply === []) {
            return $consumed;
        }
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

echo "Scenario 3b: end-to-end amphp/redis XREADGROUP loop\n";
echo "             " . TOTAL . " jobs total, COUNT=" . BATCH . ", " . RUNS . " runs each, median reported\n\n";

$config       = RedisConfig::fromUri('redis://127.0.0.1:6379');
$connectCtx   = (new ConnectContext())->withConnectTimeout($config->getTimeout());

echo "Stock amphp/redis (pure-PHP RespParser):\n";
$stock = bench('stock', static function () use ($config, $connectCtx) {
    $connector = new SocketRedisConnector($config->getConnectUri(), $connectCtx);
    return createRedisClient($config, $connector);
});

echo "\namphp/redis with AmpRedisConnector (ext-resp3):\n";
$swapped = bench('swap', static function () use ($config, $connectCtx) {
    $connector = new AmpRedisConnector($config->getConnectUri(), $connectCtx);
    return createRedisClient($config, $connector);
});

$stockRate   = $stock['consumed'] / $stock['seconds'];
$swappedRate = $swapped['consumed'] / $swapped['seconds'];
$delta       = (($swappedRate - $stockRate) / $stockRate) * 100;

$report = "## E2E amphp/redis XREADGROUP loop\n\n";
$report .= '_' . TOTAL . ' jobs, COUNT=' . BATCH . ', ' . RUNS . " runs, median reported_\n\n";
$report .= "| variant | jobs/s | seconds |\n";
$report .= "| --- | ---: | ---: |\n";
$report .= sprintf("| stock amphp/redis (pure-PHP) | %s | %.3f |\n", number_format($stockRate, 0), $stock['seconds']);
$report .= sprintf("| amphp/redis + AmpRedisConnector (C) | %s | %.3f |\n", number_format($swappedRate, 0), $swapped['seconds']);
$report .= sprintf("\n**Delta**: %+.1f%%\n", $delta);

echo "\n";
echo $report;

$out = __DIR__ . '/results/03_e2e_amphp.md';
file_put_contents($out, $report);
echo "\nWrote $out\n";

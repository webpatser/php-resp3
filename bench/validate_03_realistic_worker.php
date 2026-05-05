<?php declare(strict_types=1);
/**
 * Validation 3: realistic queue worker simulation.
 *
 * What an actual torque worker does per job:
 *   XREADGROUP COUNT=10  (one round-trip)
 *   foreach entries {
 *       payload = igbinary_unserialize(entry['payload'])
 *       hash    = simulate handler work (xxh3 over payload)
 *       XACK group entry.id    (one round-trip)
 *       XDEL stream entry.id   (one round-trip)
 *   }
 *
 * Stock vs adapter, both stacks. Reports jobs/s for the realistic loop.
 *
 *   php -d extension=./modules/resp3.so bench/validate_03_realistic_worker.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Redis\Connection\SocketRedisConnector;
use Amp\Redis\RedisConfig;
use Amp\Socket\ConnectContext;
use Fledge\Async\Redis\Protocol\ParserFactory;
use Resp3\Adapter\AmpRedisConnector;
use Resp3\Adapter\FledgeAdapter;

if (!extension_loaded('resp3')) {
    fwrite(STDERR, "ext-resp3 not loaded; run with -d extension=./modules/resp3.so\n");
    exit(1);
}
if (!extension_loaded('igbinary')) {
    fwrite(STDERR, "ext-igbinary not loaded\n");
    exit(1);
}

const STREAM_KEY = 'r3:bench:realistic';
const GROUP      = 'r3:bench:rgroup';
const CONSUMER   = 'c1';
const TOTAL      = 5_000;
const BATCH      = 10;       // realistic torque-style batch
const RUNS       = 5;

// Build a realistic job payload: small associative array, igbinary-encoded.
function make_payload(int $i): string
{
    return igbinary_serialize([
        'job'      => 'App\\Jobs\\ProcessOrder',
        'order_id' => $i,
        'user_id'  => 1000 + ($i % 100),
        'amount'   => round(10 + ($i * 0.137), 2),
        'meta'     => ['source' => 'web', 'tries' => 1, 'queue' => 'default'],
    ]);
}

function prep_stream(callable $exec): void
{
    $exec(['DEL', STREAM_KEY]);
    try { $exec(['XGROUP', 'DESTROY', STREAM_KEY, GROUP]); } catch (\Throwable) {}
    for ($i = 0; $i < TOTAL; $i++) {
        $exec(['XADD', STREAM_KEY, '*', 'payload', make_payload($i)]);
    }
    $exec(['XGROUP', 'CREATE', STREAM_KEY, GROUP, '0']);
}

/**
 * @param callable(array): mixed $exec   Run a Redis command (varargs as array).
 * @return array{consumed:int, seconds:float, hashSum:int}
 */
function realistic_loop(callable $exec): array
{
    $consumed = 0;
    $hashSum  = 0;
    $start    = microtime(true);

    while (true) {
        $reply = $exec(['XREADGROUP', 'GROUP', GROUP, CONSUMER, 'COUNT', (string) BATCH, 'STREAMS', STREAM_KEY, '>']);
        if (!is_array($reply) || $reply === []) break;
        $entries = $reply[0][1] ?? [];
        if ($entries === []) break;

        foreach ($entries as $entry) {
            // entry = [id, [k1, v1, k2, v2, ...]]
            $id     = $entry[0];
            $fields = $entry[1];

            // Locate 'payload' field (RESP2 returns flat k/v list)
            $payloadBytes = null;
            for ($i = 0, $n = count($fields); $i < $n; $i += 2) {
                if ($fields[$i] === 'payload') {
                    $payloadBytes = $fields[$i + 1];
                    break;
                }
            }
            if ($payloadBytes === null) continue;

            // Real worker work: deserialize + minimal handler hash
            $job = igbinary_unserialize($payloadBytes);
            $hashSum ^= crc32($job['job'] . '|' . $job['order_id']);

            // Acknowledge + delete (two more round-trips per job)
            $exec(['XACK', STREAM_KEY, GROUP, $id]);
            $exec(['XDEL', STREAM_KEY, $id]);

            $consumed++;
            if ($consumed >= TOTAL) break 2;
        }
    }

    return ['consumed' => $consumed, 'seconds' => microtime(true) - $start, 'hashSum' => $hashSum];
}

function bench(string $label, \Closure $execFactory): array
{
    $samples = [];
    for ($r = 0; $r < RUNS; $r++) {
        $exec = $execFactory();
        prep_stream($exec);
        $sample = realistic_loop($exec);
        $samples[] = $sample;
        printf("  [%s run %d] %d jobs in %.3fs = %.0f jobs/s (hashSum=%d)\n",
            $label, $r + 1, $sample['consumed'], $sample['seconds'],
            $sample['consumed'] / $sample['seconds'], $sample['hashSum']);
    }
    usort($samples, fn ($a, $b) => ($a['consumed'] / $a['seconds']) <=> ($b['consumed'] / $b['seconds']));
    return $samples[(int) floor(count($samples) / 2)];
}

echo "Validation 3: realistic queue worker simulation\n";
echo "  per job: XREADGROUP fetch + igbinary_unserialize + handler hash + XACK + XDEL\n";
echo "  TOTAL=" . TOTAL . " jobs, BATCH=" . BATCH . ", RUNS=" . RUNS . "\n\n";

// --- Fledge stock vs adapter ---
$fledgeExec = static function (\Fledge\Async\Redis\RedisClient $client): \Closure {
    return static fn (array $args) => $client->execute(...$args);
};

echo "Stock Fledge (pure-PHP RespParser):\n";
ParserFactory::set(null);
$fledgeStock = bench('fledge-stock', static function () use ($fledgeExec) {
    $client = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379');
    return $fledgeExec($client);
});

echo "\nFledge with FledgeAdapter (ext-resp3):\n";
ParserFactory::set(static fn (\Closure $push) => new FledgeAdapter($push));
$fledgeSwap = bench('fledge-swap', static function () use ($fledgeExec) {
    $client = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379');
    return $fledgeExec($client);
});
ParserFactory::set(null);

// --- amphp stock vs adapter ---
$config     = RedisConfig::fromUri('redis://127.0.0.1:6379');
$connectCtx = (new ConnectContext())->withConnectTimeout($config->getTimeout());
$ampExec    = static function (\Amp\Redis\RedisClient $client): \Closure {
    return static fn (array $args) => $client->execute(...$args);
};

echo "\nStock amphp/redis (pure-PHP RespParser):\n";
$ampStock = bench('amp-stock', static function () use ($ampExec, $config, $connectCtx) {
    $connector = new SocketRedisConnector($config->getConnectUri(), $connectCtx);
    return $ampExec(\Amp\Redis\createRedisClient($config, $connector));
});

echo "\namphp/redis with AmpRedisConnector (ext-resp3):\n";
$ampSwap = bench('amp-swap', static function () use ($ampExec, $config, $connectCtx) {
    $connector = new AmpRedisConnector($config->getConnectUri(), $connectCtx);
    return $ampExec(\Amp\Redis\createRedisClient($config, $connector));
});

$fmt = static function (array $r): string {
    return number_format($r['consumed'] / $r['seconds'], 0);
};
$delta = static function (array $a, array $b): float {
    $ra = $a['consumed'] / $a['seconds'];
    $rb = $b['consumed'] / $b['seconds'];
    return (($rb - $ra) / $ra) * 100;
};

$report = "## Realistic worker simulation\n\n";
$report .= '_per job: XREADGROUP fetch + igbinary_unserialize + handler hash + XACK + XDEL_\n\n';
$report .= '_TOTAL=' . TOTAL . ', BATCH=' . BATCH . ', RUNS=' . RUNS . ", median reported_\n\n";
$report .= "| stack | variant | jobs/s | seconds |\n";
$report .= "| --- | --- | ---: | ---: |\n";
$report .= sprintf("| Fledge | stock | %s | %.3f |\n", $fmt($fledgeStock), $fledgeStock['seconds']);
$report .= sprintf("| Fledge | + ext-resp3 | %s | %.3f |\n", $fmt($fledgeSwap), $fledgeSwap['seconds']);
$report .= sprintf("| amphp/redis | stock | %s | %.3f |\n", $fmt($ampStock), $ampStock['seconds']);
$report .= sprintf("| amphp/redis | + ext-resp3 | %s | %.3f |\n", $fmt($ampSwap), $ampSwap['seconds']);
$report .= "\n";
$report .= sprintf("**Fledge delta:**       %+.1f%%\n", $delta($fledgeStock, $fledgeSwap));
$report .= sprintf("**amphp/redis delta:**  %+.1f%%\n", $delta($ampStock, $ampSwap));

echo "\n";
echo $report;

$out = __DIR__ . '/results/validate_03_realistic_worker.md';
file_put_contents($out, $report);
echo "\nWrote $out\n";

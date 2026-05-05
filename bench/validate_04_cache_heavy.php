<?php declare(strict_types=1);
/**
 * Validation 4: cache-heavy read workload.
 *
 * Models a Laravel `Cache::many($keys)` pattern: one MGET round-trip returns
 * many small bulk strings, the app deserializes each value, no writes back.
 * No XACK/XDEL noise, lower per-call work than a queue worker, higher
 * parser-share of the total wall clock.
 *
 *   php -d extension=./modules/resp3.so bench/validate_04_cache_heavy.php
 *
 * Requires Redis/Valkey on 127.0.0.1:6379.
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

const KEY_PREFIX  = 'r3:cache:';
const TOTAL_KEYS  = 1_000;     // realistic Cache::many() load
const ITERATIONS  = 1_000;     // MGET calls per measurement
const RUNS        = 5;

function make_value(int $i): string
{
    // Typical cached payload: small associative array.
    return igbinary_serialize([
        'id'      => $i,
        'name'    => 'Item ' . $i,
        'price'   => round(9.99 + ($i * 0.13), 2),
        'tags'    => ['alpha', 'beta', 'gamma'],
        'updated' => '2026-05-02T11:00:00Z',
    ]);
}

function prep_cache(callable $exec): void
{
    $keys = [];
    for ($i = 0; $i < TOTAL_KEYS; $i++) $keys[] = KEY_PREFIX . $i;
    $exec(array_merge(['DEL'], $keys));
    for ($i = 0; $i < TOTAL_KEYS; $i++) {
        $exec(['SET', KEY_PREFIX . $i, make_value($i)]);
    }
}

function cache_loop(callable $exec): array
{
    $keys = [];
    for ($i = 0; $i < TOTAL_KEYS; $i++) $keys[] = KEY_PREFIX . $i;
    $args = array_merge(['MGET'], $keys);

    $hits   = 0;
    $hash   = 0;
    $start  = microtime(true);

    for ($r = 0; $r < ITERATIONS; $r++) {
        $values = $exec($args);
        if (!is_array($values)) continue;
        foreach ($values as $raw) {
            if ($raw === null) continue;
            $v = igbinary_unserialize($raw);
            $hash ^= crc32($v['name'] . '|' . $v['id']);
            $hits++;
        }
    }

    return ['hits' => $hits, 'seconds' => microtime(true) - $start, 'hash' => $hash];
}

function bench(string $label, \Closure $execFactory): array
{
    $samples = [];
    for ($r = 0; $r < RUNS; $r++) {
        $exec = $execFactory();
        prep_cache($exec);
        $sample = cache_loop($exec);
        $samples[] = $sample;
        printf("  [%s run %d] %d cache reads in %.3fs = %.0f reads/s\n",
            $label, $r + 1, $sample['hits'], $sample['seconds'],
            $sample['hits'] / $sample['seconds']);
    }
    usort($samples, fn ($a, $b) => ($a['hits'] / $a['seconds']) <=> ($b['hits'] / $b['seconds']));
    return $samples[(int) floor(count($samples) / 2)];
}

echo "Validation 4: cache-heavy read workload\n";
echo "  per call: MGET " . TOTAL_KEYS . " keys + igbinary_unserialize each value\n";
echo "  ITERATIONS=" . ITERATIONS . ", RUNS=" . RUNS . "\n\n";

$fledgeExec = static fn (\Fledge\Async\Redis\RedisClient $c): \Closure
    => static fn (array $args) => $c->execute(...$args);

echo "Stock Fledge:\n";
ParserFactory::set(null);
$fledgeStock = bench('fledge-stock', static function () use ($fledgeExec) {
    return $fledgeExec(\Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379'));
});

echo "\nFledge + ext-resp3:\n";
ParserFactory::set(static fn (\Closure $push) => new FledgeAdapter($push));
$fledgeSwap = bench('fledge-swap', static function () use ($fledgeExec) {
    return $fledgeExec(\Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379'));
});
ParserFactory::set(null);

$config     = RedisConfig::fromUri('redis://127.0.0.1:6379');
$connectCtx = (new ConnectContext())->withConnectTimeout($config->getTimeout());
$ampExec    = static fn (\Amp\Redis\RedisClient $c): \Closure
    => static fn (array $args) => $c->execute(...$args);

echo "\nStock amphp/redis:\n";
$ampStock = bench('amp-stock', static function () use ($ampExec, $config, $connectCtx) {
    $connector = new SocketRedisConnector($config->getConnectUri(), $connectCtx);
    return $ampExec(\Amp\Redis\createRedisClient($config, $connector));
});

echo "\namphp/redis + ext-resp3:\n";
$ampSwap = bench('amp-swap', static function () use ($ampExec, $config, $connectCtx) {
    $connector = new AmpRedisConnector($config->getConnectUri(), $connectCtx);
    return $ampExec(\Amp\Redis\createRedisClient($config, $connector));
});

$fmt = static fn (array $r): string => number_format($r['hits'] / $r['seconds'], 0);
$delta = static function (array $a, array $b): float {
    return ((($b['hits'] / $b['seconds']) - ($a['hits'] / $a['seconds']))
            / ($a['hits'] / $a['seconds'])) * 100;
};

$report  = "## Cache-heavy reads (MGET 1000 keys per call, igbinary_unserialize each)\n\n";
$report .= '_TOTAL_KEYS=' . TOTAL_KEYS . ', ITERATIONS=' . ITERATIONS . ', RUNS=' . RUNS . ", median reported_\n\n";
$report .= "| stack | variant | reads/s | seconds (per " . ITERATIONS . " calls) |\n";
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

$out = __DIR__ . '/results/validate_04_cache_heavy.md';
file_put_contents($out, $report);
echo "\nWrote $out\n";

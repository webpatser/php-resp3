<?php declare(strict_types=1);
/**
 * Smoke test: Fledge Redis client driving the C-level Resp3\Parser via FledgeAdapter.
 *
 * Run with the extension loaded:
 *   php -d extension=./modules/resp3.so examples/fledge_smoke.php
 *
 * Requires a running Redis or Valkey on 127.0.0.1:6379.
 */

require __DIR__ . '/../vendor/autoload.php';

use Fledge\Async\Redis\Protocol\ParserFactory;
use Resp3\Adapter\FledgeAdapter;

use function Fledge\Async\Redis\createRedisClient;

if (!extension_loaded('resp3')) {
    fwrite(STDERR, "ext-resp3 not loaded; run with -d extension=./modules/resp3.so\n");
    exit(1);
}

ParserFactory::set(static fn (\Closure $push) => new FledgeAdapter($push));

$client = createRedisClient('redis://127.0.0.1:6379');

$client->delete('r3:smoke:str', 'r3:smoke:list', 'r3:smoke:hash');

$client->execute('SET', 'r3:smoke:str', 'hello');
$got = $client->execute('GET', 'r3:smoke:str');
assert($got === 'hello', "GET mismatch: " . var_export($got, true));

$missing = $client->execute('GET', 'r3:smoke:nonexistent');
assert($missing === null, "expected null on missing key, got " . var_export($missing, true));

$incr = $client->execute('INCR', 'r3:smoke:counter');
assert(is_int($incr), "INCR should return int, got " . gettype($incr));

$client->getList('r3:smoke:list')->pushTail('a', 'b', 'c');
$range = $client->execute('LRANGE', 'r3:smoke:list', '0', '-1');
assert($range === ['a', 'b', 'c'], "LRANGE mismatch: " . json_encode($range));

$client->getMap('r3:smoke:hash')->setValue('k1', 'v1');
$client->getMap('r3:smoke:hash')->setValue('k2', 'v2');
$hash = $client->execute('HGETALL', 'r3:smoke:hash');
assert($hash === ['k1', 'v1', 'k2', 'v2'], "HGETALL mismatch: " . json_encode($hash));

$client->delete('r3:smoke:str', 'r3:smoke:list', 'r3:smoke:hash', 'r3:smoke:counter');

echo "fledge_smoke.php: OK (FledgeAdapter -> Resp3\\Parser routed " . count(get_defined_vars()) . " replies)\n";

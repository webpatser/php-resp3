<?php declare(strict_types=1);
/**
 * Validation 1: structure parity.
 *
 * Feed an identical XREADGROUP-shaped buffer through both parsers, capture
 * the materialised PHP value tree, then md5(serialize(...)) and compare.
 *
 *   php -d extension=./modules/resp3.so bench/validate_01_structure_parity.php
 */

require __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('resp3')) {
    fwrite(STDERR, "ext-resp3 not loaded; run with -d extension=./modules/resp3.so\n");
    exit(1);
}

function synthetic(int $entries = 50, int $fields = 4): string
{
    $out = "*1\r\n*2\r\n";
    $key = 'stream:bench';
    $out .= '$' . strlen($key) . "\r\n" . $key . "\r\n";
    $out .= '*' . $entries . "\r\n";
    for ($e = 0; $e < $entries; $e++) {
        $out .= "*2\r\n";
        $id = sprintf('%d-%d', 1700000000000 + $e, $e);
        $out .= '$' . strlen($id) . "\r\n" . $id . "\r\n";
        $out .= '*' . ($fields * 2) . "\r\n";
        for ($f = 0; $f < $fields; $f++) {
            $name  = "f{$f}";
            $value = "v-{$e}-{$f}";
            $out .= '$' . strlen($name)  . "\r\n" . $name  . "\r\n";
            $out .= '$' . strlen($value) . "\r\n" . $value . "\r\n";
        }
    }
    return $out;
}

$bytes = synthetic(entries: 10, fields: 3);
echo "input: " . strlen($bytes) . " bytes (1 stream, 10 entries, 3 fields)\n\n";

$collect = function (\Closure $factory) use ($bytes): array {
    $out = [];
    $parser = $factory(static function ($r) use (&$out): void {
        $out[] = $r;
    });
    $parser->push($bytes);
    return $out;
};

// php-resp3
$cParser = new \Resp3\Parser();
$cParser->feed($bytes);
$cOut = [];
while ($cParser->hasNext()) {
    $cOut[] = $cParser->next();
}

// Fledge
$fledge = $collect(static fn ($push) => new \Fledge\Async\Redis\Protocol\RespParser($push));
$fledgeUnwrapped = array_map(static fn ($r) => $r->unwrap(), $fledge);

// amphp
$amp = $collect(static fn ($push) => new \Amp\Redis\Protocol\RespParser($push));
$ampUnwrapped = array_map(static fn ($r) => $r->unwrap(), $amp);

$cHash      = md5(serialize($cOut));
$fledgeHash = md5(serialize($fledgeUnwrapped));
$ampHash    = md5(serialize($ampUnwrapped));

echo "messages produced:\n";
echo "  php-resp3:        " . count($cOut)        . "\n";
echo "  Fledge (unwrap):  " . count($fledgeUnwrapped) . "\n";
echo "  amphp  (unwrap):  " . count($ampUnwrapped)    . "\n\n";

echo "md5(serialize(output)):\n";
echo "  php-resp3:  $cHash\n";
echo "  Fledge:     $fledgeHash\n";
echo "  amphp:      $ampHash\n\n";

echo "structure identical (php-resp3 == Fledge):  " . var_export($cHash === $fledgeHash, true) . "\n";
echo "structure identical (php-resp3 == amphp):   " . var_export($cHash === $ampHash, true) . "\n\n";

if ($cHash !== $fledgeHash) {
    echo "First-entry diff (php-resp3 vs Fledge):\n";
    echo "--- php-resp3 first message (first 2 entries) ---\n";
    $sample = $cOut[0];
    if (is_array($sample) && isset($sample[0][1])) {
        var_export(array_slice($sample[0][1], 0, 2));
    } else {
        var_export($sample);
    }
    echo "\n--- Fledge first message (first 2 entries) ---\n";
    $sample = $fledgeUnwrapped[0];
    if (is_array($sample) && isset($sample[0][1])) {
        var_export(array_slice($sample[0][1], 0, 2));
    } else {
        var_export($sample);
    }
    echo "\n";
}

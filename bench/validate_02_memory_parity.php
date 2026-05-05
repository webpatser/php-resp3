<?php declare(strict_types=1);
/**
 * Validation 2: memory parity.
 *
 * Parse the same buffer with each parser, hold the result, then measure peak
 * usage and gc_status() delta. If the output structure is identical (V1
 * already proved that), allocation counts should be in the same ballpark.
 *
 *   php -d extension=./modules/resp3.so bench/validate_02_memory_parity.php
 */

require __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('resp3')) {
    fwrite(STDERR, "ext-resp3 not loaded; run with -d extension=./modules/resp3.so\n");
    exit(1);
}

function build_batch(int $messages = 20, int $entries = 25, int $fields = 4): string
{
    $one = "*1\r\n*2\r\n";
    $key = 'stream:bench';
    $one .= '$' . strlen($key) . "\r\n" . $key . "\r\n";
    $one .= '*' . $entries . "\r\n";
    for ($e = 0; $e < $entries; $e++) {
        $one .= "*2\r\n";
        $id = sprintf('%d-%d', 1700000000000 + $e, $e);
        $one .= '$' . strlen($id) . "\r\n" . $id . "\r\n";
        $one .= '*' . ($fields * 2) . "\r\n";
        for ($f = 0; $f < $fields; $f++) {
            $name  = "f{$f}";
            $value = "value-of-some-reasonable-length-{$e}-{$f}";
            $one .= '$' . strlen($name)  . "\r\n" . $name  . "\r\n";
            $one .= '$' . strlen($value) . "\r\n" . $value . "\r\n";
        }
    }
    return str_repeat($one, $messages);
}

$bytes = build_batch();
echo "input: " . number_format(strlen($bytes)) . " bytes (20 messages, 25 entries, 4 fields)\n\n";

gc_enable();

function measure(string $label, \Closure $parse): array
{
    gc_collect_cycles();
    $memBefore = memory_get_usage(true);
    $statBefore = gc_status();

    $start = microtime(true);
    $output = $parse();
    $elapsed = microtime(true) - $start;

    $statAfter = gc_status();
    $memAfter = memory_get_usage(true);
    $peak = memory_get_peak_usage(true);

    return [
        'label'     => $label,
        'count'     => is_array($output) ? count($output) : 0,
        'mem_kb'    => ($memAfter - $memBefore) / 1024,
        'peak_kb'   => $peak / 1024,
        'gc_runs'   => $statAfter['runs']      - $statBefore['runs'],
        'gc_collected' => $statAfter['collected'] - $statBefore['collected'],
        'protected' => $statAfter['protected'] - $statBefore['protected'],
        'roots'     => $statAfter['roots']     - $statBefore['roots'],
        'seconds'   => $elapsed,
    ];
}

$results = [];

$results[] = measure('php-resp3 (C)', function () use ($bytes): array {
    $p = new \Resp3\Parser();
    $p->feed($bytes);
    $out = [];
    while ($p->hasNext()) $out[] = $p->next();
    return $out;
});

$results[] = measure('Fledge RespParser (PHP)', function () use ($bytes): array {
    $out = [];
    $parser = new \Fledge\Async\Redis\Protocol\RespParser(static function ($r) use (&$out): void {
        $out[] = $r->unwrap();
    });
    $parser->push($bytes);
    return $out;
});

$results[] = measure('Amp\\Redis RespParser (PHP)', function () use ($bytes): array {
    $out = [];
    $parser = new \Amp\Redis\Protocol\RespParser(static function ($r) use (&$out): void {
        $out[] = $r->unwrap();
    });
    $parser->push($bytes);
    return $out;
});

printf("%-30s %8s %8s %8s %8s %8s %10s\n", 'parser', 'msgs', 'mem KB', 'peak KB', 'gc runs', 'collected', 'time ms');
echo str_repeat('-', 90) . "\n";
foreach ($results as $r) {
    printf("%-30s %8d %8.0f %8.0f %8d %8d %10.2f\n",
        $r['label'], $r['count'], $r['mem_kb'], $r['peak_kb'], $r['gc_runs'], $r['gc_collected'], $r['seconds'] * 1000);
}
echo "\n";
echo "Notes:\n";
echo "- mem KB:   memory_get_usage delta (held output, post-parse)\n";
echo "- peak KB:  process peak (cumulative; only meaningful for C parser baseline)\n";
echo "- gc runs:  gc_status['runs'] delta during parse\n";
echo "- collected: cycles collected by GC during parse\n";

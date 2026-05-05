<?php declare(strict_types=1);

namespace Resp3\Bench;

/**
 * Tiny benchmark harness: run a closure for a measurement window, return
 * iterations done and wall time. Caller is responsible for warmup.
 */
final class Harness
{
    /** @var list<array{label: string, iterations: int, seconds: float, bytes: int, peakBytes: int, gcRuns: int}> */
    private array $rows = [];

    public function __construct(
        public readonly string $scenario,
        public readonly float $warmupSeconds = 1.0,
        public readonly float $measureSeconds = 3.0,
        public readonly int $runs = 5,
    ) {}

    /**
     * Run $task in a loop until measureSeconds has elapsed. Returns iterations.
     */
    private function timed(\Closure $task, float $seconds): int
    {
        $iterations = 0;
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $task();
            $iterations++;
        }
        return $iterations;
    }

    /**
     * Measure a single label across $this->runs runs and record the median.
     *
     * @param \Closure(): int $task Returns bytes processed in one iteration.
     */
    public function measure(string $label, \Closure $task): void
    {
        // Warmup
        $this->timed($task, $this->warmupSeconds);

        $samples = [];
        for ($r = 0; $r < $this->runs; $r++) {
            gc_collect_cycles();
            $startMem = memory_get_peak_usage(true);
            gc_collect_cycles();
            $statBefore = gc_status();

            $bytes = 0;
            $iterations = 0;
            $start = microtime(true);
            $deadline = $start + $this->measureSeconds;
            while (microtime(true) < $deadline) {
                $bytes += $task();
                $iterations++;
            }
            $elapsed = microtime(true) - $start;

            $statAfter = gc_status();
            $peak = memory_get_peak_usage(true);

            $samples[] = [
                'iterations' => $iterations,
                'seconds'    => $elapsed,
                'bytes'      => $bytes,
                'peakBytes'  => $peak - $startMem,
                'gcRuns'     => $statAfter['runs'] - $statBefore['runs'],
            ];
        }

        // Median by iterations/sec
        usort($samples, fn ($a, $b) => ($a['iterations'] / $a['seconds']) <=> ($b['iterations'] / $b['seconds']));
        $median = $samples[(int) floor(count($samples) / 2)];

        $this->rows[] = ['label' => $label, ...$median];
    }

    public function report(): string
    {
        $out  = "## " . $this->scenario . "\n\n";
        $out .= sprintf("_runs=%d, warmup=%.1fs, measure=%.1fs, median reported_\n\n", $this->runs, $this->warmupSeconds, $this->measureSeconds);
        $out .= "| label | iters/s | MB/s | peak MB | GC cycles |\n";
        $out .= "| --- | ---: | ---: | ---: | ---: |\n";
        foreach ($this->rows as $r) {
            $itersPerSec = $r['iterations'] / $r['seconds'];
            $mbPerSec    = ($r['bytes'] / $r['seconds']) / (1024 * 1024);
            $peakMb      = $r['peakBytes'] / (1024 * 1024);
            $out .= sprintf("| %s | %s | %s | %s | %d |\n",
                $r['label'],
                number_format($itersPerSec, 0),
                number_format($mbPerSec, 1),
                number_format($peakMb, 2),
                $r['gcRuns'],
            );
        }
        return $out;
    }

    /** @return list<array{label: string, iterations: int, seconds: float, bytes: int, peakBytes: int, gcRuns: int}> */
    public function rows(): array
    {
        return $this->rows;
    }
}

# Benchmark results

_Generated: 2026-05-02T10:05:46Z_
_Host: Darwin 25.4.0 arm64_
_PHP: PHP 8.5.5 (cli) (built: Apr  7 2026 16:24:10) (NTS)_

## Parser throughput (XREADGROUP-shaped, 1×10×3, one feed per iteration)

_runs=5, warmup=1.0s, measure=3.0s, median reported_

| label | iters/s | MB/s | peak MB | GC cycles |
| --- | ---: | ---: | ---: | ---: |
| php-resp3 (C) | 508,611 | 1,060.8 | 0.00 | 0 |
| Fledge RespParser (PHP) | 16,559 | 34.5 | 0.00 | 0 |
| Amp\Redis RespParser (PHP) | 16,520 | 34.5 | 0.00 | 0 |

## Allocation pressure (20-message XREADGROUP batch per iteration)

_runs=5, warmup=1.0s, measure=3.0s, median reported_

| label | iters/s | MB/s | peak MB | GC cycles |
| --- | ---: | ---: | ---: | ---: |
| php-resp3 (C) | 8,606 | 1,158.4 | 0.00 | 0 |
| Fledge RespParser (PHP) | 33 | 4.4 | 0.00 | 0 |
| Amp\Redis RespParser (PHP) | 32 | 4.3 | 0.00 | 0 |

## E2E Fledge XREADGROUP loop

_50000 jobs, COUNT=100, 5 runs, median reported_

| variant | jobs/s | seconds |
| --- | ---: | ---: |
| stock Fledge (pure-PHP) | 77,193 | 0.648 |
| Fledge + FledgeAdapter (C) | 623,262 | 0.080 |

**Delta**: +707.4%

## E2E amphp/redis XREADGROUP loop

_50000 jobs, COUNT=100, 5 runs, median reported_

| variant | jobs/s | seconds |
| --- | ---: | ---: |
| stock amphp/redis (pure-PHP) | 79,235 | 0.631 |
| amphp/redis + AmpRedisConnector (C) | 609,131 | 0.082 |

**Delta**: +668.8%


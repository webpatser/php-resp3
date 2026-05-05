## Parser throughput (XREADGROUP-shaped, 1×10×3, one feed per iteration)

_runs=5, warmup=1.0s, measure=3.0s, median reported_

| label | iters/s | MB/s | peak MB | GC cycles |
| --- | ---: | ---: | ---: | ---: |
| php-resp3 (C) | 473,574 | 987.7 | 0.00 | 0 |
| Fledge RespParser (PHP) | 16,829 | 35.1 | 0.00 | 0 |
| Amp\Redis RespParser (PHP) | 16,775 | 35.0 | 0.00 | 0 |

## Allocation pressure (20-message XREADGROUP batch per iteration)

_runs=5, warmup=1.0s, measure=3.0s, median reported_

| label | iters/s | MB/s | peak MB | GC cycles |
| --- | ---: | ---: | ---: | ---: |
| php-resp3 (C) | 8,606 | 1,158.4 | 0.00 | 0 |
| Fledge RespParser (PHP) | 33 | 4.4 | 0.00 | 0 |
| Amp\Redis RespParser (PHP) | 32 | 4.3 | 0.00 | 0 |

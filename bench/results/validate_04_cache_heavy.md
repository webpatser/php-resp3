## Cache-heavy reads (MGET 1000 keys per call, igbinary_unserialize each)

_TOTAL_KEYS=1000, ITERATIONS=1000, RUNS=5, median reported_

| stack | variant | reads/s | seconds (per 1000 calls) |
| --- | --- | ---: | ---: |
| Fledge | stock | 419,080 | 2.386 |
| Fledge | + ext-resp3 | 1,329,098 | 0.752 |
| amphp/redis | stock | 402,398 | 2.485 |
| amphp/redis | + ext-resp3 | 1,365,005 | 0.733 |

**Fledge delta:**       +217.1%
**amphp/redis delta:**  +239.2%

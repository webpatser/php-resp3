## Realistic worker simulation

_per job: XREADGROUP fetch + igbinary_unserialize + handler hash + XACK + XDEL_\n\n_TOTAL=5000, BATCH=10, RUNS=5, median reported_

| stack | variant | jobs/s | seconds |
| --- | --- | ---: | ---: |
| Fledge | stock | 13,838 | 0.361 |
| Fledge | + ext-resp3 | 14,739 | 0.339 |
| amphp/redis | stock | 13,858 | 0.361 |
| amphp/redis | + ext-resp3 | 15,036 | 0.333 |

**Fledge delta:**       +6.5%
**amphp/redis delta:**  +8.5%

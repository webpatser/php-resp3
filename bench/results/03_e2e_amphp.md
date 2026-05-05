## E2E amphp/redis XREADGROUP loop

_50000 jobs, COUNT=100, 5 runs, median reported_

| variant | jobs/s | seconds |
| --- | ---: | ---: |
| stock amphp/redis (pure-PHP) | 79,235 | 0.631 |
| amphp/redis + AmpRedisConnector (C) | 609,131 | 0.082 |

**Delta**: +668.8%

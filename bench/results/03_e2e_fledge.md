## E2E Fledge XREADGROUP loop

_50000 jobs, COUNT=100, 5 runs, median reported_

| variant | jobs/s | seconds |
| --- | ---: | ---: |
| stock Fledge (pure-PHP) | 77,193 | 0.648 |
| Fledge + FledgeAdapter (C) | 623,262 | 0.080 |

**Delta**: +707.4%

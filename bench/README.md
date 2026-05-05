# Benchmarks

Three scenarios, run with the C parser and the two pure-PHP RESP parsers it
aims to replace.

| Scenario | What it measures |
| --- | --- |
| `01_parser_throughput.php` | Pure parsing speed on a synthetic XREADGROUP-shaped buffer. |
| `02_allocation_pressure.php` | Same shape, larger batch, GC and peak memory pressure. |
| `03_e2e_fledge.php` | End-to-end XREADGROUP loop through Fledge, stock vs FledgeAdapter. |
| `03_e2e_amphp.php` | End-to-end XREADGROUP loop through amphp/redis, stock vs AmpRedisConnector. |

## Setup

```bash
# Build the extension (from repo root)
phpize
./configure --enable-resp3
make

# Install dev deps (amphp/redis + amphp/amp)
composer install --ignore-platform-req=ext-resp3
```

End-to-end scenarios need a running Redis or Valkey on `127.0.0.1:6379`.

The Fledge end-to-end scenario (`03_e2e_fledge.php`) needs `webpatser/fledge-fiber`. That package is not in the public release manifest, so a clone has to bring its own install:

```bash
composer require --dev webpatser/fledge-fiber:dev-feature/php-resp3-parser
```

If you skip that step, `bench/run.sh --skip-e2e` skips both end-to-end scenarios; running `01_parser_throughput.php` and `02_allocation_pressure.php` individually still works without Fledge.

## Run

```bash
bench/run.sh                   # full suite, writes bench/results/latest.md
bench/run.sh --skip-e2e        # skip the two scenarios that need Redis
```

Each scenario also runs standalone:

```bash
php -d extension=./modules/resp3.so bench/01_parser_throughput.php
```

## What the numbers do and don't show

The pure parser comparisons (scenarios 1 and 2) measure parsing in isolation:
how fast bytes turn into PHP values when nothing else is happening. The
end-to-end scenarios (3a and 3b) include socket I/O, Fiber scheduling, and
the rest of the client pipeline; they show the parser swap impact on a
realistic queue worker loop.

The benchmarks run on macOS, which means no `taskset`-style CPU pinning.
Disable Turbo Boost in Settings if you want lower variance, otherwise expect
~5% run-to-run noise on the pure parser scenarios and ~2% on the end-to-end
ones (which are dominated by Redis loopback latency).

Apples to apples: the synthetic buffer is RESP2-shaped on purpose, since
both Fledge and amphp/redis only speak RESP2 on the wire. The C parser
handles RESP2 as a subset of RESP3, so this is a fair head-to-head for the
workload these clients actually emit.

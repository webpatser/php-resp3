# Benchmarks

The `bench/` directory has four scenarios plus four validation scripts
that double as harness sanity checks. Every measurement here ran
locally on macOS 15 ARM64 with PHP 8.5.5 against Valkey 9.0.3 on the
loopback interface. Run the same scripts on your own hardware before
trusting the numbers; loopback latency and CPU model both matter.

## Scenarios at a glance

| Scenario | What it measures | What it does not measure |
| --- | --- | --- |
| Pure parser bytes per second | Bytes through the parser, no work after parse | Anything that touches the parsed values |
| Allocation pressure | Same shape, larger held output, allocation count | Real GC behaviour under concurrent load |
| Batched fetch parser throughput | XREADGROUP with a 100 element batch, no work per entry, no XACK | What a queue worker does per job |
| Realistic worker simulation | XREADGROUP with a 10 element batch, plus `igbinary_unserialize` per entry, plus a tiny handler hash, plus XACK and XDEL per entry | Multi-fiber concurrency, fanout, retry handling |
| Cache-heavy reads | MGET 1000 keys per call, plus `igbinary_unserialize` per value | Writes back to cache, eviction effects |

A separate script (`bench/validate_01_structure_parity.php`) confirms
that the C parser produces output identical to both pure-PHP parsers
via `md5(serialize($value))`. Without that check the speedup numbers
below would be meaningless.

## Numbers

### Pure parser bytes per second

Synthetic XREADGROUP-shaped buffer, one stream with ten entries of
three fields each (2,187 bytes per iteration).

| Parser | Iters per second | MB per second |
| --- | ---: | ---: |
| ext-resp3 (C) | 511,332 | 1,066.5 |
| Fledge `RespParser` (PHP) | 16,573 | 34.6 |
| `Amp\Redis\RespParser` (PHP) | 16,640 | 34.7 |

Speedup: about 31x. This is parser isolation, no work after parse,
output structure verified identical.

### Allocation pressure

Twenty messages of 25 entries with four fields each (141,140 bytes per
iteration), output held to keep the parsed arrays alive.

| Parser | Iters per second | MB per second |
| --- | ---: | ---: |
| ext-resp3 (C) | 8,728 | 1,174.8 |
| Fledge `RespParser` (PHP) | 33 | 4.4 |
| `Amp\Redis\RespParser` (PHP) | 32 | 4.2 |

Speedup: about 265x on this shape. Neither parser triggered a GC
cycle in the measurement window; the gap is allocation count, not GC
overhead.

### Batched fetch parser throughput

XREADGROUP with COUNT=100, draining a 50,000 entry stream in 500
round-trips. The loop calls `count()` on the outer array; nothing
touches an individual entry.

| Stack | Variant | Entries per second |
| --- | --- | ---: |
| Fledge | stock | 77,193 |
| Fledge | + ext-resp3 | 623,262 |
| amphp/redis | stock | 79,235 |
| amphp/redis | + ext-resp3 | 609,131 |

Speedup: about 8x in this loop. The loop is parser-bound by
construction. Use this as "if the work after parse is negligible, the
parser swap matters this much."

### Realistic worker simulation

XREADGROUP with COUNT=10, then for each entry: `igbinary_unserialize`
the payload, run a tiny handler that hashes the deserialized job, then
XACK and XDEL. 5,000 jobs total, 15,000+ round-trips.

| Stack | Variant | Jobs per second |
| --- | --- | ---: |
| Fledge | stock | 13,838 |
| Fledge | + ext-resp3 | 14,739 |
| amphp/redis | stock | 13,858 |
| amphp/redis | + ext-resp3 | 15,036 |

Speedup: 6.5% (Fledge), 8.5% (amphp/redis). Three round-trips per job
and per-entry handler work means loopback latency and Valkey CPU
dominate. A 31x parser speedup turns into a single-digit percentage
delta. That is exactly what Amdahl's law predicts.

### Cache-heavy reads

MGET 1000 keys per call, `igbinary_unserialize` each value, no writes
back. 1,000 calls per measurement.

| Stack | Variant | Reads per second |
| --- | --- | ---: |
| Fledge | stock | 419,080 |
| Fledge | + ext-resp3 | 1,329,098 |
| amphp/redis | stock | 402,398 |
| amphp/redis | + ext-resp3 | 1,365,005 |

Speedup: 217% (Fledge), 239% (amphp/redis), about 3.2x to 3.4x. One
round-trip returns a thousand small bulk strings, the parser does
real work, the per-value work is light. This is the shape where the
parser swap moves the needle.

## Where the parser earns its keep

The four scenarios above split cleanly into two camps.

Parser pays off when:

- One round-trip returns many small values (cache-heavy `MGET`,
  `HGETALL` on big hashes, `Cache::tags(...)->remember()` patterns).
- The application work per parsed value is light (deserialize, lookup,
  compose a response).
- Latency to the Redis instance is low relative to the time the parser
  spends materialising PHP values.

Parser does not pay off when:

- Per-job work is heavy (handler logic, business rules, additional
  Redis round-trips per job).
- Per-job round-trip count is greater than one (XACK + XDEL after every
  XREADGROUP entry, for example).
- The Redis instance is a network hop away and round-trip latency
  drowns out everything in the application process.

If your workload is closer to "queue worker with handler logic and
multiple round-trips per job," the C parser will give you a single
digit percent improvement at best. If your workload is closer to
"cache fan-out where parsing is the real cost," expect a 2x to 3x
improvement. Run the relevant scenario from `bench/` against your own
fixtures before adopting.

## Methodology lessons

A few things this set of benchmarks taught me about measuring parser
speedups specifically. Worth remembering before adding new scenarios.

### Realistic-workload measurement first, parser microbench last

The first version of `bench/03_e2e_amphp.php` measured XREADGROUP
COUNT=100 with no work after the fetch. It reported a 707% speedup and
called itself "end-to-end." That number was technically correct for
the loop it measured, but the loop was a parser microbenchmark inside
a fiber, not an end-to-end queue worker. Calling it E2E set the
realistic worker scenario up to look like a regression rather than the
calibration it actually was.

The lesson: before writing parser-isolation scripts, write the most
realistic scenario you can. Use it to find the parser share of total
wall clock. Then the parser-isolation numbers serve as upper bounds
("if parsing were free, you'd save this much time") instead of as
adoption claims.

### The wire protocol of the target client matters

The original plan assumed amphp/redis would speak RESP3 because it was
the modern non-blocking PHP client. Both Fledge and amphp/redis turned
out to be RESP2-only on the wire. RESP2 is a subset of RESP3 from a
parser perspective so the C parser handles it without issue, but the
"RESP3-specific win" framing was always going to be misleading. Read
the target client's transport before scoping the comparison.

### Label what you measure

Every script in `bench/` now has a header that says exactly what the
loop does and what it does not. The headers were retrofitted after
the calibration story above. If you add a scenario, add the header
first, then write the benchmark.

## How to reproduce

```bash
# Build the extension (from repo root)
phpize
./configure --enable-resp3
make

# Install the dev deps that the bench harness needs
composer install --ignore-platform-req=ext-resp3

# Start a Redis or Valkey on 127.0.0.1:6379 (any modern version works)
# Refresh the captured fixtures if you want to bench against your server
tools/capture_fixtures.sh

# Run everything (about 90 seconds)
bench/run.sh

# Or skip the scenarios that need Redis
bench/run.sh --skip-e2e
```

The harness writes per-scenario reports to `bench/results/*.md` and
combines them into `bench/results/latest.md`. The numbers in this
file came from `bench/results/SUMMARY.md` after running on a stock
Apple Silicon laptop with Turbo Boost disabled.

If you want apples-to-apples comparisons across machines, pin the CPU
governor to performance, disable Turbo Boost, run the harness with
nothing else competing for cores, and report the median of at least
five runs. The `Harness` class in `bench/src/Harness.php` already
takes the median over five runs by default; the rest is up to your
operating system.

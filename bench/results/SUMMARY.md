# Benchmark summary, with honest labels

All numbers measured locally on macOS 15 ARM64, PHP 8.5.5, Valkey 9.0.3 on
loopback. Parser output structure verified identical via md5(serialize()).
Median over 5 runs.

## What each scenario actually measures

| Scenario | What it measures | What it does NOT measure |
| --- | --- | --- |
| **Pure parser bytes/sec** | Bytes through parser, no work after parse | Anything that touches the parsed values |
| **Allocation pressure** | Same, larger batches | GC behaviour under sustained load |
| **Batched-fetch parser throughput** | XREADGROUP COUNT=100 + parse, no per-entry work, no XACK | What a queue worker does per job |
| **Realistic worker simulation** | XREADGROUP COUNT=10 + igbinary_unserialize + handler hash + XACK + XDEL per entry | Multi-fiber concurrency, fanout, retry handling |

The previous "E2E" label on the batched-fetch scenario was misleading. It
measures parsing throughput inside a fiber loop, not queue worker throughput.

## Numbers

### 1. Pure parser bytes/sec (XREADGROUP-shaped, 1 stream × 10 entries × 3 fields, 2,187 bytes per iteration)

| parser | iters/s | MB/s |
| --- | ---: | ---: |
| ext-resp3 (C) | 511,332 | 1,066.5 |
| Fledge RespParser (PHP) | 16,573 | 34.6 |
| amphp/redis RespParser (PHP) | 16,640 | 34.7 |

**Speedup: ~31x.** This is real, parser-isolated, structure-verified.

### 2. Allocation pressure (20 messages × 25 entries × 4 fields per iteration)

| parser | iters/s | MB/s |
| --- | ---: | ---: |
| ext-resp3 (C) | 8,728 | 1,174.8 |
| Fledge RespParser (PHP) | 33 | 4.4 |
| amphp/redis RespParser (PHP) | 32 | 4.2 |

**Speedup: ~265x** when output is held and the parser is exercised on
larger nested structures. Not GC-cycle dominated (no cycles collected on
either parser; allocation count itself drives the gap).

### 3. Batched-fetch parser throughput (XREADGROUP COUNT=100, 50,000 entries, 500 round-trips total)

| stack | variant | entries/s | seconds |
| --- | --- | ---: | ---: |
| Fledge | stock | 77,193 | 0.648 |
| Fledge | + ext-resp3 | 623,262 | 0.080 |
| amphp/redis | stock | 79,235 | 0.631 |
| amphp/redis | + ext-resp3 | 609,131 | 0.082 |

**Speedup: ~8x in this loop, but the loop is parser-bound by construction.**
500 round-trips amortised over 50,000 entries means parsing dominates the
wall clock. Per-entry work is just `count()` on the outer array; nothing
touches an individual entry. Use this number for "if the work after parse
is negligible, parser swap matters this much."

### 4. Realistic worker simulation (XREADGROUP COUNT=10, igbinary_unserialize + handler + XACK + XDEL per entry, 5,000 jobs, 15,000+ round-trips)

| stack | variant | jobs/s | seconds |
| --- | --- | ---: | ---: |
| Fledge | stock | 13,838 | 0.361 |
| Fledge | + ext-resp3 | 14,739 | 0.339 |
| amphp/redis | stock | 13,858 | 0.361 |
| amphp/redis | + ext-resp3 | 15,036 | 0.333 |

**Speedup: +6.5% Fledge, +8.5% amphp/redis.**

This is the honest end-to-end number. Per job: one fetch round-trip
(amortised across the batch), one igbinary_unserialize, a tiny handler
hash, then two more round-trips (XACK + XDEL). Three round-trips per job
means loopback latency and Valkey CPU dominate; parser improvement gets
diluted to a rest-percentage.

## What this means for the stop criterion

PLAN.md line 185 sets the stop criterion at **>=15% end-to-end delta in an
ideal scenario**. Realistic worker simulation comes in at 6.5–8.5%, which
is **below** that threshold.

The PoC is a real parser improvement with verified structure parity. It is
not, on this measurement, a launch-defining feature for queue workers
where round-trip latency and per-job work dominate.

## Open questions before the stop decision

1. Does torque do XDEL per job, or only XACK? Skipping XDEL would shift
   one round-trip out of the per-job cost and bump the parser-share back up.
2. Does torque batch XACK across entries (single XACK ID-1 ID-2 ...)?
   That would amortise more network work per parser call.
3. What does the mixed/fanout/async-io workload split look like under
   `torque:bench`? Parser-share may be higher in some workload profiles.

These three questions decide whether Day 12-13 (torque integration) is
worth running, or whether we cut to retrospective and archive.

# Changelog

All notable changes to this project go here. Format follows
[Keep a Changelog 1.1][kac]. Versions follow [Semantic Versioning 2.0][semver]
once the project hits 1.0; until then, breaking changes can land in any 0.x
minor and are called out in the entry.

## [0.1.0] - 2026-05-05

First public release. The parser handles the full RESP3 wire type set
and ships drop-in adapters for Fledge and amphp/redis.

### Added

- `Resp3\Parser` userland class with `feed()`, `hasNext()`, `next()`,
  `reset()`, and `lastAttributes()`. Constructor takes `maxDepth`,
  `maxBulk`, and `maxAggregateCount` for tuning.
- Wrapper classes: `Resp3\VerbatimString` (readonly `type` and
  `value` for `=` payloads), `Resp3\PushMessage` (readonly `payload`
  for `>` frames), `Resp3\RedisException` (extends `\RuntimeException`,
  used for both `-` and `!` errors and protocol violations).
- C state machine in `resp3_parser.c` with explicit aggregate stack,
  pause and resume safe streaming (byte by byte input produces
  identical output to whole-buffer input), 16 KiB compaction
  threshold on the rolling buffer.
- All RESP3 wire types: simple string `+`, error `-`, integer `:`,
  bulk string `$`, array `*`, null `_`, double `,` (including `inf`,
  `-inf`, `nan`), boolean `#`, big number `(`, map `%`, set `~`,
  verbatim string `=`, blob error `!`, push `>`, attribute `|`.
- Drop-in adapters: `Resp3\Adapter\FledgeAdapter` (implements Fledge
  `ParserInterface`), `Resp3\Adapter\AmpRedisConnector` plus
  `AmpRedisConnection` (mirror of the amphp/redis classes that lets a
  custom connector slot in via `createRedisClient($config, $connector)`).
- Functional phpt suite (`tests/00*..tests/04*`) plus security suite
  (`tests/050_..053_*`) for adversarial wire input, plus spec-edge
  suite (`tests/060_..068_*`) covering digit-count and INT64
  boundaries on length parsing, error prefix preservation for
  `-WRONGTYPE`, `-MOVED`, and `-ASK`, the captured HELLO 3 reply
  shape, the pubsub push frame envelope, attribute frames attached
  to empty aggregates, random-chunk streaming (32 to 1024 byte reads
  instead of byte by byte), and inline command rejection.
- Benchmark suite under `bench/` with four labelled scenarios and
  parity verification via `md5(serialize(...))`.
- Fixture capture tool (`tools/capture_fixtures.sh`) using `socat` as
  a one-shot client. Captures land in `tests/fixtures/02_resp3/`.
- PIE distribution support: `composer.json` declares
  `type: "php-ext"` with a `php-ext` block exposing the
  `--enable-resp3` configure option. Install with
  `pie install webpatser/php-resp3`.
- CI matrix: PHP 8.4 and 8.5 across Ubuntu 24.04 (x64 and ARM64) and
  macOS 15, plus Alpine 3.22 (musl, PHP 8.4), ZTS variants of PHP 8.4
  and 8.5, a Valgrind memcheck job, and a `pie-install` job that
  verifies the PIE install path on every push.

### Changed

- The parser validates the first byte of every wire message against
  the legitimate RESP3 prefix set. Unknown bytes (including ASCII
  letters that look like inline commands) raise a friendly
  `RESP3 parse error: unknown RESP wire type 0x...; this parser
  handles server-to-client RESP3 traffic, not inline commands` so
  the direction mismatch is obvious.
- `resp3_arginfo.h` is generated against PHP 8.4 minimum. The 8.3
  hand-tweak header is gone; the file uses the modern
  `zend_register_internal_class_with_flags()` directly. Anyone
  touching the stub regenerates with
  `gen_stub.php --minimum-php-version=8.4`.

### Removed

- PHP 8.3 support. Minimum PHP version is 8.4. PIE itself requires
  PHP 8.4+, and supporting 8.3 forced the parser to keep the
  `zend_register_internal_class_ex` compatibility hand-tweak in
  `resp3_arginfo.h` plus matrix-CI overhead with no observable
  adoption benefit. PHP 8.3 users can stay on a fork that re-applies
  the hand-tweak.

### Security

- Length values are capped at 19 decimal digits to keep the
  multiply-add accumulator inside `int64_t`.
- Per-bulk byte cap (`maxBulk`, default 512 MiB) and per-aggregate
  element cap (`maxAggregateCount`, default 1M) reject adversarial
  sizes before any allocation.
- Map and attribute counts are checked against `maxAggregateCount / 2`
  before the doubling that tracks key-value pairs, so a count near
  `INT64_MAX/2` cannot wrap negative.
- Inline lines (`+`, `-`, `:`, `,`, `#`, `(`, `_`) are capped at 64
  KiB to prevent a long integer line from growing the line buffer
  unbounded.
- Verbatim string type prefix is restricted to three ASCII
  alphanumeric characters; non-conforming prefixes fall back to an
  empty `type` with the full payload in `value`.
- `lastAttributes()` is one-shot: reading consumes the slot, so a
  stale attribute from a prior reply cannot leak into a later read.
- Re-calling `__construct()` on an existing parser throws `ValueError`
  instead of leaking previous state.
- Phpt suite runs clean under Valgrind (`make test TESTS="-m"` with
  `USE_ZEND_ALLOC=0`); no leaks or invalid memory access.

### Documented

- Streamed types (`$?`, `*?`, `~?`, `%?`) are explicitly out of
  scope for v0.1, deferred to v0.2. README lists them under Known
  limitations; ARCHITECTURE notes the scope.

### Notes

API surface is `v0.x`: it may change between minor releases until a
1.0 stabilises it. The parser output structure has been verified
identical to the pure-PHP RespParsers in Fledge and amphp/redis via
`bench/validate_01_structure_parity.php`; that contract will not
break in a minor release.

[0.1.0]: https://github.com/webpatser/php-resp3/releases/tag/v0.1.0

[kac]: https://keepachangelog.com/en/1.1.0/
[semver]: https://semver.org/spec/v2.0.0.html

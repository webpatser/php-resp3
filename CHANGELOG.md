# Changelog

All notable changes to this project go here. Format follows
[Keep a Changelog 1.1][kac]. Versions follow [Semantic Versioning 2.0][semver]
once the project hits 1.0; until then, breaking changes can land in any 0.x
minor and are called out in the entry.

## [0.2.0] - 2026-10-03

### Added

- Push queue API. `new Resp3\Parser(queuePushes: true)` diverts top-level
  push frames (`>`) into a queue. Read them with `hasPush()` and
  `nextPush(): ?PushMessage`, so a consumer reading N pipelined replies
  no longer misaligns when an invalidation push arrives in between.
  Default mode is unchanged: pushes still come out of `next()` as
  `Resp3\PushMessage`.
- `Resp3\RedisException::$prefix`: the first `[A-Z0-9_]` token of the
  server error (`ERR`, `WRONGTYPE`, `MOVED`, `NOAUTH`), or an empty
  string when there is none. Parser faults use `PROTOCOL`.
- `-nan` is accepted as a double, next to `nan`, `inf` and `-inf`.
- Bulk fast path: when the whole payload is already buffered, the string
  is built straight from the buffer without the intermediate copies.

### Changed

- Nested errors (`-`, `!`) and verbatim strings (`=`) are wrapped at any
  depth, for example inside `EXEC` results. Before, only top-level ones
  became `Resp3\RedisException` and `Resp3\VerbatimString`.
- A null bulk error `!-1` or null verbatim `=-1` is now a protocol error
  instead of an exception with an empty message.
- Protocol errors latch. After a parse error `hasNext()` and `next()` throw
  `parser is in error state; call reset()` until you call `reset()`.
  `feed()` and `reset()` never throw. `hasPush()` and `nextPush()` first
  hand out pushes queued before the fault, then throw once the queue
  is empty.
- `next()` on a parser with no message throws `LogicException` instead
  of `Resp3\RedisException`, so misuse no longer looks like a wire fault.
- The `maxDepth` ceiling drops from 100000 to 10000.
- Calling `__construct()` again on an existing parser discards its
  state instead of throwing.
- Only `$-1` and `*-1` are null (RESP2 compatibility). `~-1`, `>-1`,
  `%-1`, `|-1`, `-0` and lengths with leading zeros are protocol
  errors. Before, negative lengths on `$`, `*`, `~`, `>`, `!` and `=`
  read as null (`%-1` and `|-1` were already rejected in 0.1.4).
- Nested error replies now construct exception objects, and each one
  captures a backtrace, so an `EXEC` with many failed commands costs
  more than before. This is a conscious trade for correctness.
- The push queue holds at most `maxAggregateCount` pushes; the next one
  is a protocol error (`push queue limit exceeded; drain with
  nextPush()`).
- Map keys use symtable semantics: numeric strings such as `"123"` become
  the integer key `123`, so `$map[123]` and `$map["123"]` both hit.
  Aggregate map keys are rejected instead of being cast to a string with
  an "Array to string conversion" warning.
- Attributes are cleared when the next top-level message starts, so
  `lastAttributes()` can no longer return attributes from an earlier reply.
- `composer.json` no longer sets `minimum-stability`, and `amphp/redis`
  was added to `suggest`; it stays in `require-dev` for the adapter
  tests.
- `AmpRedisConnection` also catches `Resp3\RedisException` in its read
  loop and resets the parser on a `PROTOCOL` fault.

### Fixed

- Allocation amplification: the unverified header count no longer drives
  `array_init_size`. The preallocation hint is capped at 1024 slots for
  a top-level aggregate and 64 for nested ones, so `*1000000\r\n:1\r\n` no longer pins megabytes.
- Integer and double parsing is strict and locale independent. Whitespace,
  a leading `+`, hex and `infinity` are rejected.
- Error messages with an embedded NUL byte are no longer truncated.
- Reflection cannot skip the constructor (final internal class) and the
  class is not serializable, so a Parser without caps can no longer exist.

### Security

- Closes the count-driven preallocation class of bug, the same class as
  the hiredis multibulk preallocation issue. No CVE is claimed for this
  project; no release before 0.2.0 shipped a known exploit.
- Lowering the `maxDepth` ceiling to 10000 keeps recursive destruction of
  deeply nested arrays inside the C stack.

## [0.1.4] - 2026-08-17

### Fixed

- Nested RESP2 nulls (`$-1` / `*-1` as elements of an aggregate) corrupted
  the state machine: `finalize_length` delivered the null but never reset
  the state, so the parser re-entered `LEN_LF` and misread the next
  element's type byte as a missing LF ("expected LF after CR in length").
  Top-level nulls were unaffected because completion resets the state on
  consumption. Seen live as the `XPENDING` summary reply
  (`*4 :0 $-1 $-1 *-1`) and `MGET` with missing keys failing on every
  RESP2 connection. Both null paths now advance to the next type byte
  before delivering. New regression test: `tests/043_resp2_null_nested.phpt`.

## [0.1.3] - 2026-07-14

### Fixed

- `tests/001_load.phpt` hardcoded the version string, so `make test` on
  the v0.1.2 source tree reported one failure. The test now checks that
  `resp3_version()` matches `phpversion('resp3')` and looks like a
  semver triple, so release bumps no longer touch it. Test-only change;
  the extension itself is identical to 0.1.2.

## [0.1.2] - 2026-07-14

### Fixed

- A NUL (0x00) type byte is now rejected like any other unknown wire
  byte. The type whitelist used `strchr`, which also matches a string's
  terminating NUL, so 0x00 slipped past the check and reached the line
  parser. The check now uses `memchr` bounded to the real type
  characters.
- A length field of just `-` (as in `$-\r\n` or `*-\r\n`) is now
  rejected as an empty length. It used to finalize as -0 == 0 and was
  delivered as an empty bulk string or empty array instead of raising
  `Resp3\RedisException`. RESP2 null lengths (`$-1`, `*-1`) still parse
  as null.

### Changed

- Test 068 now covers all invalid type bytes in one loop, including the
  0x00 regression case, and asserts the error message names the
  offending byte.

## [0.1.1] - 2026-05-05

### Added

- Prebuilt PIE binaries for six common combos: PHP 8.4 + 8.5 on
  linux/x86_64/glibc/NTS, linux/arm64/glibc/NTS, and darwin/arm64/NTS.
  `pie install webpatser/php-resp3` now downloads the matching `.zip`
  from the GitHub Release for these combos instead of compiling from
  source. Other combos (Alpine/musl, ZTS, x86 32-bit, Windows) keep
  using the source-compile fallback transparently.
- `.github/workflows/release-binaries.yml`: triggered on
  `release: published`. Runs `php/pie-ext-binary-builder@0.0.2`
  across the six-row matrix, then a follow-up smoke matrix that
  installs the package via `pie install webpatser/php-resp3:<ver>`
  on Ubuntu and macOS to verify the prebuilt path resolves end-to-end
  against Packagist before marking the release green.

### Changed

- `composer.json` `php-ext` block now declares
  `download-url-method: ["pre-packaged-binary", "composer-default"]`
  so PIE walks the prebuilt assets first and falls back to source
  compile only on combos without a prebuilt asset.
- README leads with `pie install webpatser/php-resp3`. The
  Supported platforms section now splits into a Prebuilt-binaries
  table (six combos) and a Source-compile fallback table (the
  existing CI matrix).
- ARCHITECTURE adds a Distribution section with the asset name
  pattern, the prebuilt matrix, and the rationale for what is
  intentionally not prebuilt.

### Notes

- Prebuilt assets carry the leading `v` from the release tag in
  the filename (e.g. `php_resp3-v0.1.1_php8.4-x86_64-linux-glibc.zip`).
  PIE strips the prefix when matching, so `pie install webpatser/php-resp3:0.1.1`
  resolves correctly.
- Linux smoke jobs invoke `sudo pie install` because the runner
  extension dir is not user-writable. macOS GitHub runners are.
  End users on Linux who do not have a passwordless sudo can also
  use `pie install --allow-non-interactive-project-install` for a
  per-project install, or run `sudo pie install` themselves.

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

[0.1.3]: https://github.com/webpatser/php-resp3/releases/tag/v0.1.3
[0.1.2]: https://github.com/webpatser/php-resp3/releases/tag/v0.1.2
[0.1.1]: https://github.com/webpatser/php-resp3/releases/tag/v0.1.1
[0.1.0]: https://github.com/webpatser/php-resp3/releases/tag/v0.1.0

[kac]: https://keepachangelog.com/en/1.1.0/
[semver]: https://semver.org/spec/v2.0.0.html

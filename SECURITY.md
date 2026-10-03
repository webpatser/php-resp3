# Security policy

## Reporting a vulnerability

Send an email to `oss@downsized.nl`. Use the subject line
`[php-resp3 security]` so it lands in the right inbox. Encrypted mail
welcome, plain text fine.

Include:

- A short description of the issue.
- A minimal reproducer if possible (wire bytes, the PHP snippet that
  triggers it, the version of the extension and PHP).
- Your assessment of impact, even if it is just a guess.

The maintainer acknowledges within seven days. For confirmed issues,
expect a fix and coordinated disclosure within thirty days unless the
fix needs upstream PHP changes, in which case both of you agree on the
timing.

Please do not open a public GitHub issue for security reports until a
fix is available.

## Scope

The parser treats wire input as untrusted. In-scope issues:

- Memory safety problems triggered by adversarial wire bytes (out of
  bounds reads or writes, use after free, double free, leak under
  attacker control).
- Logic flaws that let crafted input crash the PHP process or escape
  the configured `maxBulk`, `maxAggregateCount`, or `maxDepth` caps.
- Integer overflow or underflow in length parsing that bypasses the
  caps above.
- Anything in the `Resp3\Adapter\*` classes that lets a hostile server
  cause undefined behaviour in Fledge or amphp/redis client code.

Out of scope (open a regular issue instead):

- Performance regressions on benign input.
- Documentation gaps.
- Build system breakage on platforms not in the supported matrix.
- Vulnerabilities in transitive dev dependencies that do not affect a
  built extension.

## What we consider safe

- The caps (default depth 100, bulk 512 MiB, aggregate 1M elements)
  are checked before any state changes, so a rejected header never
  allocates. The depth ceiling of 10000 is a hard upper bound, not a
  recommended value: ZTS builds with small thread stacks, or `var_dump`
  of a very deep value, may want a lower `maxDepth` (the default stays
  100).
- The push queue holds at most `maxAggregateCount` pushes. When it is
  full, the next push is a protocol error (`push queue limit exceeded;
  drain with nextPush()`), so a consumer must drain after each reply.
- Preallocation never trusts the header count: array and map storage
  is reserved for at most 1024 slots up front (64 for nested aggregates) and grows only as
  elements actually arrive. A 15 byte frame that claims a million
  elements costs a few kilobytes, not megabytes.
- Integers and doubles are parsed strictly and independent of the
  locale: no whitespace, no leading `+`, no hex, no `infinity`.
- A protocol error latches the parser. It refuses further input until
  `reset()`, so it cannot resume in the middle of a malformed message.
- Inline lines (`+`, `-`, `:`, `,`, `#`, `(`, `_`) are capped at 64
  KiB (`RESP3_MAX_INLINE_LINE`, 65536 bytes) regardless of the constructor settings.
- Length values may not have more than 19 decimal digits, which keeps
  the multiply-add accumulator inside `int64_t`.
- The verbatim string type prefix is restricted to three ASCII
  alphanumeric characters; everything else falls back to an empty
  type with the full payload in `value`.

The `tests/050_*.phpt` through `tests/057_*.phpt` set, together with
`tests/058`, `059`, `071`, `072` and `077`, covers each of these guards, and CI runs the full suite under Valgrind on Ubuntu.

## Hall of fame

Once we receive and resolve real reports, we list reporters here with
their consent. Until then this section is empty.

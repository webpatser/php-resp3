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

- The default caps (depth 100, bulk 512 MiB, aggregate 1M elements)
  prevent a hostile server from exhausting host memory or wrapping
  signed integers.
- Inline lines (`+`, `-`, `:`, `,`, `#`, `(`, `_`) are capped at 64
  KiB regardless of the constructor settings.
- Length values may not have more than 19 decimal digits, which keeps
  the multiply-add accumulator inside `int64_t`.
- The verbatim string type prefix is restricted to three ASCII
  alphanumeric characters; everything else falls back to an empty
  type with the full payload in `value`.

The `tests/050_*.phpt` through `tests/057_*.phpt` set covers each of
these guards, and CI runs the full suite under Valgrind on Ubuntu.

## Hall of fame

Once we receive and resolve real reports, we list reporters here with
their consent. Until then this section is empty.

# php-resp3

[![CI](https://github.com/webpatser/php-resp3/actions/workflows/ci.yml/badge.svg)](https://github.com/webpatser/php-resp3/actions/workflows/ci.yml)

A small PECL extension that turns [RESP3][resp3-spec] wire bytes into PHP
values. That's the whole job. No sockets, no commands, no connection
management. You feed it bytes, you pull out parsed messages.

> [!NOTE]
> v0.x: API may change. Output structure is verified identical to pure-PHP
> parsers via `bench/validate_01_structure_parity.php`. Realistic queue worker
> simulation lands at +6.5% to +8.5% versus pure-PHP parsers; cache-heavy MGET
> fan-out lands at +217% to +239% on the same harness. See [`BENCHMARKS.md`][bench]
> for the labelled measurements and [`ARCHITECTURE.md`][arch] for the design
> notes.

## Quickstart

```bash
# Install with PIE (recommended)
pie install webpatser/php-resp3

# Or build from source
git clone git@github.com:webpatser/php-resp3.git && cd php-resp3
phpize && ./configure --enable-resp3 && make
```

Try it:

```bash
php -r '
    $p = new Resp3\Parser();
    $p->feed("*2\r\n+OK\r\n:42\r\n");
    while ($p->hasNext()) { var_dump($p->next()); }
'
```

If you built from source rather than using PIE, prefix the command with
`-d extension=./modules/resp3.so`.

## Why this exists

Pure-PHP RESP parsers are generator-based and dominate wall clock when one
round-trip returns many small values. A `Cache::many($keys)` call with a
thousand keys spends most of its PHP time inside the parser's generator
state machine, not in the application. This extension parses the same wire
bytes in C and exposes the same value tree, which moves the bottleneck
elsewhere for cache-heavy workloads.

It does not move the needle on queue workers where round-trip latency and
per-job handler work dominate. See the [type mapping](#type-mapping) for
the produced shapes and [BENCHMARKS.md][bench] for the workloads where it
helps and where it does not.

## Table of contents

- [Userland API](#userland-api)
- [Type mapping](#type-mapping)
- [Requirements](#requirements)
- [Build from source](#build-from-source)
- [Supported platforms](#supported-platforms)
- [Test fixtures](#test-fixtures)
- [Where this helps and where it doesn't](#where-this-helps-and-where-it-doesnt)
- [Security model](#security-model)
- [Known limitations](#known-limitations)
- [More documentation](#more-documentation)
- [License](#license)

## Userland API

```php
$p = new Resp3\Parser($maxDepth = 100);

$p->feed($bytes);                  // append bytes (no parse work)
while ($p->hasNext()) {            // drive the state machine; throws on protocol error
    $msg = $p->next();             // grab the buffered value
    // $msg may be: any scalar (incl. null/false from wire), array, or wrapper object
}

$attr = $p->lastAttributes();      // attributes (`|`) attached to the last value, or null
$p->reset();                       // wipe state and start fresh
```

Splitting `hasNext()` and `next()` keeps "need more bytes" out of the return
value. That matters: every PHP scalar (`null`, `false`, `0`, `""`) is a real
RESP3 wire value, and you don't want any of them stolen as a sentinel.

## Type mapping

| RESP3 wire                              | PHP value                                       |
| :-------------------------------------- | :---------------------------------------------- |
| `+OK\r\n` simple string                 | `string`                                        |
| `:42\r\n` integer                       | `int`                                           |
| `$N\r\n…\r\n` bulk string (binary-safe) | `string`                                        |
| `$-1\r\n` null bulk                     | `null`                                          |
| `*N\r\n…` array                         | `array` (indexed)                               |
| `*-1\r\n` null array                    | `null`                                          |
| `_\r\n` null                            | `null`                                          |
| `,1.5\r\n` / `,inf\r\n` / `,nan\r\n`    | `float`                                         |
| `#t\r\n` / `#f\r\n` boolean             | `bool`                                          |
| `(N…\r\n` big number                    | `string` (PHP has no native bignum)             |
| `%N\r\n…` map                           | `array` (associative)                           |
| `~N\r\n…` set                           | `array` (indexed; PHP has no native set)        |
| `=N\r\nxxx:payload\r\n` verbatim string | `Resp3\VerbatimString { type, value }`          |
| `>N\r\n…` push                          | `Resp3\PushMessage { payload }`                 |
| `\|N\r\n…` attribute                    | attached to parser; read via `lastAttributes()` |
| `-ERR …\r\n` error                      | `Resp3\RedisException` (returned, not thrown)   |
| `!N\r\n…\r\n` blob error                | `Resp3\RedisException` (returned, not thrown)   |

## Requirements

- PHP 8.4 or 8.5
- A C compiler (clang on macOS, gcc on Linux)

## Build from source

The usual PECL dance:

```bash
phpize
./configure --enable-resp3
make
make test
```

The build drops `modules/resp3.so` in place. Load it like so:

```bash
php -d extension=./modules/resp3.so -r 'echo resp3_version();'
```

## Supported platforms

| Build               | x64 | ARM64 | PHP versions |
| :------------------ | :-: | :---: | :----------- |
| Ubuntu 24.04 (NTS)  |  ✓  |   ✓   | 8.4, 8.5     |
| macOS 15 (NTS)      | n/a |   ✓   | 8.4, 8.5     |
| Alpine 3.22 (musl)  |  ✓  |  n/a  | 8.4          |
| Ubuntu 24.04 (ZTS)  |  ✓  |  n/a  | 8.4, 8.5     |

That works out to 9 build combinations plus a Valgrind memcheck run and
a PIE install verification, all in parallel on GitHub Actions. PHP 8.6
lands when it goes GA.

## Test fixtures

Real wire bytes off a running Redis/Valkey, captured with [`socat`][socat]:

```bash
brew install socat                       # macOS
sudo apt-get install socat               # Debian, Ubuntu

tools/capture_fixtures.sh                # defaults to 127.0.0.1:6379
tools/capture_fixtures.sh 10.0.0.5 6380  # custom host and port
```

Each fixture is a fresh TCP session: `HELLO 3` first, then the target command.
So the `.bin` files in `tests/fixtures/02_resp3/` carry two messages each (the
HELLO map and the command reply). Tests handle both and don't assume a single
message per file.

`tests/fixtures/handcrafted/` covers the wire edges Redis won't hand you on
its own: `NaN`, positive and negative infinity, big numbers, empty and null
aggregates, and a 99-level nested array for the deep-stack tests.

## Where this helps and where it doesn't

The benchmarks tell a clear story. Use the C parser when parsing owns a
meaningful share of your wall clock:

- Cache-heavy reads (`MGET` fan-out, tag invalidation, large hash GETs) where
  one round-trip returns many small bulk strings.
- Workloads where the application work per parsed value is light.
- FalkorDB / RedisGraph queries that return deep nested arrays.

Skip it when round-trip latency or per-job work dominates:

- Queue workers with one fetch + one ACK + one DEL per job. The parser share
  of total wall clock is small enough that even a 30x parser speedup lands
  as single-digit percent end-to-end.

The adapters in `src/Adapter/` and the harness in `bench/run.sh` are the
fastest path to measuring on your own workload before adopting.

## Security model

The parser treats wire input as untrusted. A malicious or buggy server can
send arbitrary bytes; the goal is to throw a `Resp3\RedisException` rather
than crash the PHP process or eat all RAM. Three caps protect against
adversarial input, all configurable on the constructor:

```php
$p = new Resp3\Parser(
    maxDepth: 100,                  // aggregate nesting (default 100, max 100000)
    maxBulk: 536_870_912,           // bytes per bulk string (default 512 MiB, max 2 GiB)
    maxAggregateCount: 1_000_000,   // elements per array/set/push (or pairs for map)
);
```

The parser also caps inline lines (`+`, `-`, `:`, `,`, `#`, `(`, `_`) at
64 KiB, rejects length values with more than 19 digits, detects signed
integer overflow before it happens, and refuses to multiply a map or
attribute count when doubling it would wrap. All of these surface as
`RESP3 parse error: …` exceptions you can catch.

Two userland gotchas that are not parser bugs but matter for security:

- `Resp3\VerbatimString::$type` is server-supplied. The parser only
  accepts a 3-character ASCII alphanumeric prefix; anything else falls
  back to an empty `$type` with the full payload in `$value`. Even with
  that filter, treat `$type` as untrusted when interpolating into log
  lines, headers, filenames, or shell commands.
- `Resp3\Parser::lastAttributes()` is one-shot. Reading it returns the
  attribute payload from the most recent `|` frame and clears the slot,
  so a stale attribute from a prior reply cannot accidentally bleed into
  a later context.

Calling `__construct()` a second time on an existing `Resp3\Parser`
throws `ValueError`. Use `reset()` to recycle an instance.

The `tests/050_*.phpt` through `tests/057_*.phpt` set covers each of
these guards. CI runs the full suite under Valgrind on Ubuntu (see the
`valgrind` workflow job).

## Known limitations

A few RESP corners that this version does not handle. None of them
trip a real Redis or Valkey server in 2026; if your workload hits one
anyway, open an issue.

- **Streamed types** (`$?`, `*?`, `~?`, `%?`). The RESP3 specification
  defines streamed bulk strings, arrays, sets, and maps with chunk
  framing and end markers, but Redis itself excludes them from its
  shipped protocol support and no server command emits them today.
  Planned for v0.2 if a real workload needs them.
- **Inline commands** (`PING\r\n` style telnet input). The spec lists
  this as a client-to-server fallback only. The parser sits on the
  server-to-client side and rejects an unknown first byte with a
  clear message that points at the direction mismatch.

## More documentation

- [`ARCHITECTURE.md`][arch] for the state machine, frame stack, and
  pause/resume contract.
- [`BENCHMARKS.md`][bench] for the four labelled scenarios, what each
  one measures, and how to reproduce.
- [`CHANGELOG.md`][changelog] for release notes.
- [`CONTRIBUTING.md`][contrib] if you want to send a patch.
- [`SECURITY.md`][security] for the disclosure process and threat model.

## License

[MIT](./LICENSE).

[resp3-spec]: https://github.com/redis/redis-specifications/blob/master/protocol/RESP3.md
[socat]: http://www.dest-unreach.org/socat/
[arch]: ./ARCHITECTURE.md
[bench]: ./BENCHMARKS.md
[changelog]: ./CHANGELOG.md
[contrib]: ./CONTRIBUTING.md
[security]: ./SECURITY.md

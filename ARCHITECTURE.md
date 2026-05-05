# Architecture

## What the parser does

Bytes go in via `feed()`. Parsed PHP values come out via `next()`. That is
the entire interface.

The parser holds no socket, no connection, no fiber, no thread. The
caller is responsible for getting bytes off the wire and pushing them in.
Output values are plain PHP scalars and arrays, plus three small wrapper
classes (`Resp3\VerbatimString`, `Resp3\PushMessage`,
`Resp3\RedisException`) for cases where a bare value would be ambiguous.

## State machine

A switch-case dispatcher with eight states. Every state either consumes
bytes from the input buffer and transitions, or returns
`RESP3_PARSE_NEED_MORE` if the buffer ran out mid-state. Nothing here
recurses; aggregate nesting lives on an explicit stack.

```
                     +------------+
                     |    TYPE    |  read one byte, dispatch on type
                     +-----+------+
                           |
              +------------+------------+
              |                         |
              v                         v
         (length-prefixed)       (inline scalar)
              |                         |
              v                         v
       +-------------+           +-------------+
       |     LEN     |           |    LINE     |
       | digits, '-' |           | until '\r'  |
       +------+------+           +------+------+
              |                         |
              v                         v
       +-------------+           +-------------+
       |   LEN_LF    |           |   LINE_LF   |
       |  expect '\n'|           | expect '\n' |
       +------+------+           +------+------+
              |                         |
   (bulk family)|                       |
              v                       (deliver scalar to top of stack
       +-------------+                  or to the completed slot)
       |  BULK_DATA  |
       | read N bytes|
       +------+------+
              |
              v
       +-------------+
       |   BULK_CR   |
       |  expect '\r'|
       +------+------+
              |
              v
       +-------------+
       |   BULK_LF   |
       | expect '\n' |
       +------+------+
              |
              v
       (deliver bulk to top of stack
        or to the completed slot)
```

Every transition out of a `*_LF` state runs `deliver_value()`. That
function appends the value to the aggregate at the top of the stack
(array, set, push, map, attribute), or if the stack is empty, lands the
value in `parser.completed` and returns `RESP3_PARSE_COMPLETE`.

## Frame stack

Aggregates allocate one `resp3_frame_t` on a small heap-grown stack.
Each frame remembers its type byte, the number of children still
expected, the accumulator zval (a fresh PHP array), and for maps and
attributes, whether the next child is a key or a value.

```c
typedef struct {
    char         type;
    int64_t      count;
    zval         accum;
    int          map_key_pending;
    zend_string *pending_key;
} resp3_frame_t;
```

Stack depth is capped by `maxDepth` (default 100). The cap is checked
in `stack_push()` before any allocation, so a `*1\r\n` chain that goes
beyond the limit gets rejected before it can grow the stack one extra
frame.

## Pause and resume

The parser is asynchronous-friendly: a caller can feed one byte at a
time and the output is identical to feeding the whole buffer at once.
Three rules make that work.

1. `feed()` only appends to `parser.buf`. It never parses.
2. `next()` calls into `resp3_parser_step()`, which advances `parser.pos`
   and updates `parser.state`. If the buffer runs out mid-state, step
   returns `NEED_MORE` without advancing `pos` past the partial frame
   and without mutating any user-visible zval.
3. The state machine pauses at byte boundaries. There is no half-decoded
   integer hanging in a register: the partial accumulator lives in
   `parser.int_acc`, the partial line in `parser.line_acc`, and so on.

The streaming tests under `tests/030_*` and `tests/031_*` and
`tests/041_*` feed every fixture byte by byte and verify that the
output matches the whole-buffer feed.

## Buffer strategy

`parser.buf` is a `smart_str` that grows as bytes come in. `parser.pos`
tracks how far the parser has consumed. When `pos` exceeds 16 KiB and
also exceeds half the buffer length, `maybe_compact()` shifts unconsumed
bytes back to offset zero and resets `pos`. This keeps a long-lived
parser from leaking memory while still avoiding a `memmove()` on every
call.

Inline lines (`+`, `-`, `:`, `,`, `#`, `(`, `_`) accumulate in a
separate `smart_str` capped at 64 KiB. Bulk payloads accumulate in the
same `line_acc` slot, capped by the per-bulk byte limit
(`maxBulk`, default 512 MiB).

## Type to PHP value mapping

| Wire prefix | RESP3 type | PHP value |
| --- | --- | --- |
| `+` | simple string | `string` |
| `-` | error | `Resp3\RedisException` (returned, not thrown) |
| `:` | integer | `int` |
| `$` | bulk string | `string` (binary-safe), `null` if length is `-1` |
| `*` | array | indexed `array`, `null` if length is `-1` |
| `_` | null | `null` |
| `,` | double | `float`, including `INF`, `-INF`, `NAN` |
| `#` | boolean | `bool` |
| `(` | big number | `string` (PHP has no native bignum) |
| `%` | map | associative `array` |
| `~` | set | indexed `array` (PHP has no native set type) |
| `=` | verbatim string | `Resp3\VerbatimString { type, value }` |
| `!` | blob error | `Resp3\RedisException` (returned, not thrown) |
| `>` | push | `Resp3\PushMessage { payload }` |
| `\|` | attribute | held on the parser, read once via `lastAttributes()` |

The two error types (`-` and `!`) return a `Resp3\RedisException`
instance instead of throwing. That way an array of mixed values can
contain one or more errors without short-circuiting the whole batch.
Userland code can route on `instanceof Resp3\RedisException`.

## RESP2 compatibility

RESP3 is a strict superset of RESP2 from a parser perspective. The five
RESP2 types (`+`, `-`, `:`, `$`, `*`) carry the same wire format in
both protocols. A server that speaks RESP2 only (the default for any
client that does not send `HELLO 3`) produces input the parser handles
without issue. The drop-in adapters for Fledge and amphp/redis both
target RESP2 traffic in their current versions; the parser handles
their output through the same code path that handles RESP3.

## PHP integration

`resp3.c` registers four classes in `MINIT`:

| Class | Role |
| --- | --- |
| `Resp3\Parser` | the userland handle, holds a `resp3_parser_t` via `XtOffsetOf` |
| `Resp3\RedisException` | extends `\RuntimeException`, used for both error wire types and for protocol violations thrown from `next()` |
| `Resp3\VerbatimString` | readonly `type` and `value` properties for `=` payloads |
| `Resp3\PushMessage` | readonly `payload` array for `>` frames |

Object handlers come from `std_object_handlers` with a custom
`free_obj` (calls `resp3_parser_dtor` so the C state goes away with the
PHP object) and `clone_obj = NULL` (parsers carry mid-message state
that does not clone meaningfully).

Arginfo is generated from `resp3.stub.php` via PHP's `gen_stub.php`
with `--minimum-php-version=8.4`. The generator uses
`zend_register_internal_class_with_flags()` directly; PHP 8.4 is the
project minimum so the older 8.3 helper is not required.

## Design notes

A few decisions worth knowing about before changing the parser.

### Switch-case over computed goto

Computed-goto state machines (the labels-as-values GCC extension) make
sense for byte-level interpreters where dispatch happens once per byte
and the JIT can specialise. RESP parsing dispatches once per type byte,
not per data byte; the saving is in the noise. Switch-case is portable
to every supported compiler and every reader who is going to debug
this.

### Explicit stack over recursion

Recursive descent for arrays-of-arrays would mean the C call stack
holds parser state. A 99 level deep aggregate would burn 99 stack
frames. With the explicit `resp3_frame_t` stack, depth is bounded by
`maxDepth` and the runtime stack stays flat. The
`tests/042_fixture_handcrafted.phpt` test exercises 99 nested arrays.

### Adapters are lossy on RESP3 wrapper types

`Resp3\Adapter\FledgeAdapter` and `Resp3\Adapter\AmpRedisAdapter`
unwrap `Resp3\VerbatimString` to its `value` and `Resp3\PushMessage`
to its `payload` before handing the result to the consumer. That makes
the adapter behave like the RESP2 RespParsers those clients ship.
Consumers that need the wrapper types should call `Resp3\Parser`
directly instead of going through the adapter.

### One-shot attributes

`lastAttributes()` returns the attribute payload from the most recent
`|` frame and immediately clears the slot. Reading twice returns
`null` the second time. This stops a stale attribute from a prior
reply leaking into a later context. If you need the same attribute in
two places, capture it in a local variable on the first read.

### Wire input is untrusted

The parser does its own bounds checking on every length-prefixed
value. See `SECURITY.md` for the threat model and the test set under
`tests/050_*.phpt` for the adversarial cases.

## Scope and limitations

Three things this parser deliberately does not handle.

- **Direction**: server-to-client only. Inline commands
  (`PING\r\n`) and pipelined client-side bytes belong on a different
  layer. The parser rejects them at the type-byte check with a
  friendly error so the direction mismatch is obvious.
- **Streamed types**: `$?`, `*?`, `~?`, `%?` from the RESP3
  specification. Redis itself excludes them from its protocol
  support and no command emits them today; deferred to v0.2.
- **Wire encoding**: the parser assumes valid CRLF (`\r\n`) line
  endings as the spec requires. It does not accept lone LF
  separators, even though some legacy tooling emits them.

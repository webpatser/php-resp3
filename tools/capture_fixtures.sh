#!/usr/bin/env bash
# Capture raw RESP3 wire bytes from a running Redis/Valkey using socat.
# Each fixture = one TCP session containing HELLO 3 + the target command,
# so the resulting .bin holds two messages: the HELLO reply followed by
# the command reply. Tests parse all messages from a fixture.
#
# Usage: tools/capture_fixtures.sh [host] [port]

set -euo pipefail

HOST="${1:-127.0.0.1}"
PORT="${2:-6379}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/tests/fixtures/02_resp3"
HAND="$ROOT/tests/fixtures/handcrafted"

command -v socat >/dev/null || { echo "socat not installed (brew install socat)" >&2; exit 1; }

mkdir -p "$OUT" "$HAND"

# Encode a list of args as a RESP2 array of bulk strings.
# Servers accept this regardless of negotiated RESP version.
encode() {
    local n=$#
    printf '*%d\r\n' "$n"
    for arg in "$@"; do
        printf '$%d\r\n%s\r\n' "${#arg}" "$arg"
    done
}

# Open one socat session, send HELLO 3 then the given command, capture all bytes.
capture() {
    local out="$1"; shift
    { encode HELLO 3; encode "$@"; } | socat -t 0.5 - TCP:"$HOST":"$PORT" > "$out"
    printf '  wrote %-22s (%d bytes)\n' "$(basename "$out")" "$(wc -c < "$out")"
}

echo "Capturing RESP3 fixtures from $HOST:$PORT via socat..."

# Pre-clean state so fixtures are deterministic. Separate session so it doesn't
# pollute the captures.
{
    encode HELLO 3
    encode DEL r3:str r3:int r3:list r3:set r3:hash r3:stream
    encode XGROUP DESTROY r3:stream g1
    encode RPUSH _setup _ignore
    encode DEL _setup
} | socat -t 0.5 - TCP:"$HOST":"$PORT" > /dev/null || true

# Single-command captures (each is a fresh TCP session: HELLO + command).
capture "$OUT/hello.bin"        HELLO 3
capture "$OUT/set_ok.bin"       SET r3:str hello
capture "$OUT/get_string.bin"   GET r3:str
capture "$OUT/get_null.bin"     GET r3:nonexistent
capture "$OUT/incr.bin"         INCR r3:int
capture "$OUT/client_info.bin"  CLIENT INFO
capture "$OUT/error.bin"        EVAL "return redis.error_reply('ERR custom failure')" 0

# Multi-command setups need state laid down first via separate sessions.
{ encode HELLO 3; encode SADD r3:set a b c; } | socat -t 0.5 - TCP:"$HOST":"$PORT" > /dev/null
capture "$OUT/smembers.bin"     SMEMBERS r3:set

{ encode HELLO 3; encode HSET r3:hash k1 v1 k2 v2; } | socat -t 0.5 - TCP:"$HOST":"$PORT" > /dev/null
capture "$OUT/hgetall.bin"      HGETALL r3:hash

{ encode HELLO 3; encode RPUSH r3:list one two three; } | socat -t 0.5 - TCP:"$HOST":"$PORT" > /dev/null
capture "$OUT/lrange.bin"       LRANGE r3:list 0 -1

{
    encode HELLO 3
    encode XADD r3:stream '*' f1 v1 f2 v2
    encode XADD r3:stream '*' f1 v3
    encode XGROUP CREATE r3:stream g1 0 MKSTREAM
} | socat -t 0.5 - TCP:"$HOST":"$PORT" > /dev/null
capture "$OUT/xreadgroup.bin"   XREADGROUP GROUP g1 c1 COUNT 10 STREAMS r3:stream '>'

# Hand-crafted edges that Redis won't naturally emit.
echo "Writing handcrafted fixtures..."
write_hand() {
    local name="$1"; shift
    printf '%b' "$1" > "$HAND/$name"
    printf '  wrote %-22s (%d bytes)\n' "$name" "$(wc -c < "$HAND/$name")"
}

write_hand null.bin             '_\r\n'
write_hand bool_true.bin        '#t\r\n'
write_hand bool_false.bin       '#f\r\n'
write_hand double_pos.bin       ',3.14\r\n'
write_hand double_neg.bin       ',-2.5\r\n'
write_hand double_inf.bin       ',inf\r\n'
write_hand double_neg_inf.bin   ',-inf\r\n'
write_hand double_nan.bin       ',nan\r\n'
write_hand big_number.bin       '(3492890328409238509324850943850943825024385\r\n'
write_hand empty_bulk.bin       '$0\r\n\r\n'
write_hand null_bulk.bin        '$-1\r\n'
write_hand empty_array.bin      '*0\r\n'
write_hand null_array.bin       '*-1\r\n'
write_hand empty_map.bin        '%0\r\n'
write_hand empty_set.bin        '~0\r\n'
write_hand blob_error.bin       '!21\r\nSYNTAX invalid syntax\r\n'
write_hand push.bin             '>2\r\n+pubsub\r\n+message\r\n'
write_hand attribute.bin        '|1\r\n+key\r\n+value\r\n+payload\r\n'
write_hand verbatim.bin         '=15\r\ntxt:Some string\r\n'

# 99-deep nested array — single +leaf at the bottom.
{
    printf '*1\r\n'
    for _ in $(seq 1 98); do printf '*1\r\n'; done
    printf '+leaf\r\n'
} > "$HAND/deep_nesting_99.bin"
printf '  wrote %-22s (%d bytes)\n' "deep_nesting_99.bin" "$(wc -c < "$HAND/deep_nesting_99.bin")"

echo "Done."

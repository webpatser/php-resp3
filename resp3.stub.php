<?php

/**
 * @generate-class-entries
 * @generate-function-entries
 */

namespace {
    /**
     * Returns the version of the php-resp3 extension.
     */
    function resp3_version(): string {}
}

namespace Resp3 {

    /**
     * Incremental RESP3 wire-protocol parser.
     *
     * @not-serializable
     */
    final class Parser
    {
        /**
         * Bounds protect against adversarial wire input that would otherwise
         * exhaust memory. Defaults are large enough for normal Redis traffic
         * (XREADGROUP COUNT=1000, MGET 100k keys, multi-MB cached values).
         *
         * Length prefixes: only `$-1` and `*-1` are null; every other negative length,
         * `-0` and lengths with leading zeros are protocol errors.
         *
         * @param int $maxDepth            Aggregate-nesting limit (default 100, max 10000)
         * @param int $maxBulk             Max bytes per bulk string (default 512 MiB, max 2 GiB)
         * @param int $maxAggregateCount   Max elements per array/set/push, or pairs * 2 for map (default 1M, max 100M)
         * @param bool $queuePushes        When true, top-level push frames (`>`) are queued and read with
         *                                 hasPush()/nextPush() instead of being returned by next()
         */
        public function __construct(
            int $maxDepth = 100,
            int $maxBulk = 536870912,
            int $maxAggregateCount = 1000000,
            bool $queuePushes = false,
        ) {}

        /**
         * Append bytes to the internal buffer. Performs no parse work.
         */
        public function feed(string $bytes): void {}

        /**
         * True if a complete message has been buffered and is ready for next().
         * False if the buffer is incomplete. Throws RedisException on protocol error.
         * Calling hasNext() advances the state machine; the parsed value is held until next() consumes it.
         */
        public function hasNext(): bool {}

        /**
         * Returns the message previously detected by hasNext(). If hasNext() has not been called
         * (or returned false) since the last next(), this advances the state machine itself.
         * Throws RedisException (prefix "PROTOCOL") on protocol error, and LogicException
         * when no complete message is available.
         *
         * Errors (`-` and `!`) are returned as Resp3\RedisException instances (not thrown)
         * so consumers can route on instanceof. Nested errors and verbatim strings (`=`) are
         * wrapped at any depth. `!-1` and `=-1` are protocol errors.
         *
         * Length prefixes: only `$-1` and `*-1` are null. Every other negative length,
         * `-0` and lengths with leading zeros are protocol errors.
         *
         * Once a protocol error has been thrown the parser stays in an error state:
         * call reset() before feeding more bytes.
         *
         * In queue mode (queuePushes: true), top-level `>` frames never come out of next();
         * read them with nextPush(). In default mode they are returned as PushMessage.
         */
        public function next(): mixed {}

        /**
         * True if a push message (`>`) is queued. Only meaningful in queue mode
         * (queuePushes: true); always false in default mode. Advances the state machine
         * like hasNext(), so a buffered push is found without a regular reply.
         *
         * Does not parse past an unconsumed regular reply: a push behind a pending reply
         * becomes visible only after that reply is taken with next(). The queue is bounded
         * by maxAggregateCount: when it is full, the next push is a protocol error and the
         * parser must be reset(). Drain the queue after each reply.
         *
         * Pushes queued before a protocol error remain retrievable after it; once the queue
         * is empty, this method surfaces the latched error until reset().
         */
        public function hasPush(): bool {}

        /**
         * Removes and returns the oldest queued push message, or null when the queue is empty.
         * Drives the state machine first, like hasNext(). Does not parse past an unconsumed
         * regular reply (take it with next() first). The queue is bounded by maxAggregateCount;
         * on overflow the parser raises a protocol error and must be reset(), so drain after
         * each reply.
         * Pushes queued before a protocol error remain retrievable after it; once the queue
         * is empty, this method surfaces the latched error until reset().
         */
        public function nextPush(): ?PushMessage {}

        /**
         * Discard all internal state and return to a fresh parser.
         */
        public function reset(): void {}

        /**
         * Attributes (`|`) attached to the most recently returned value, or null.
         * Reading consumes the attribute payload: a second call returns null until
         * the parser receives a new attribute frame.
         * Attributes belong to the message that follows them and are also cleared when the
         * next top-level message starts, so after a reply without attributes it returns null.
         */
        public function lastAttributes(): ?array {}
    }

    class RedisException extends \RuntimeException
    {
        /**
         * The leading uppercase token of the server error, for example ERR, WRONGTYPE,
         * MOVED, ASK or NOAUTH. Empty string when the message has no such token.
         * Parser faults (malformed wire bytes) use PROTOCOL.
         */
        public string $prefix = '';
    }

    /**
     * Wrapper for RESP3 verbatim string (`=`). Carries the type prefix (e.g. "txt", "mkd")
     * separately from the payload so consumers can route on format.
     *
     * Security note: $type is server-supplied untrusted input. The parser only
     * accepts a 3-character ASCII alphanumeric prefix; anything else falls back
     * to an empty $type with the full payload in $value. Even so, treat $type
     * as untrusted when interpolating into log lines, headers, or filenames.
     *
     * @strict-properties
     */
    final class VerbatimString
    {
        public readonly string $type;
        public readonly string $value;

        public function __construct(string $type, string $value) {}
    }

    /**
     * Wrapper for RESP3 push messages (`>`). Allows consumers to distinguish
     * server-pushed events (pubsub, tracking invalidation) from regular replies via instanceof.
     *
     * @strict-properties
     */
    final class PushMessage
    {
        public readonly array $payload;

        public function __construct(array $payload) {}
    }
}

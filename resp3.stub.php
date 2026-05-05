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
     */
    final class Parser
    {
        /**
         * Bounds protect against adversarial wire input that would otherwise
         * exhaust memory. Defaults are large enough for normal Redis traffic
         * (XREADGROUP COUNT=1000, MGET 100k keys, multi-MB cached values).
         *
         * @param int $maxDepth            Aggregate-nesting limit (default 100, max 100000)
         * @param int $maxBulk             Max bytes per bulk string (default 512 MiB, max 2 GiB)
         * @param int $maxAggregateCount   Max elements per array/set/push, or pairs * 2 for map (default 1M, max 100M)
         */
        public function __construct(
            int $maxDepth = 100,
            int $maxBulk = 536870912,
            int $maxAggregateCount = 1000000,
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
         * Throws RedisException on protocol error or when no complete message is available.
         *
         * Errors (`-` and `!`) are returned as Resp3\RedisException instances (not thrown)
         * so consumers can route on instanceof.
         */
        public function next(): mixed {}

        /**
         * Discard all internal state and return to a fresh parser.
         */
        public function reset(): void {}

        /**
         * Attributes (`|`) attached to the most recently returned value, or null.
         * Reading consumes the attribute payload: a second call returns null until
         * the parser receives a new attribute frame.
         */
        public function lastAttributes(): ?array {}
    }

    class RedisException extends \RuntimeException
    {
    }

    /**
     * Wrapper for RESP3 verbatim string (`=`). Carries the type prefix (e.g. "txt", "mkd")
     * separately from the payload so consumers can route on format.
     *
     * Security note: $type is server-supplied untrusted input. The parser only
     * accepts a 3-character ASCII alphanumeric prefix; anything else falls back
     * to an empty $type with the full payload in $value. Even so, treat $type
     * as untrusted when interpolating into log lines, headers, or filenames.
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
     */
    final class PushMessage
    {
        public readonly array $payload;

        public function __construct(array $payload) {}
    }
}

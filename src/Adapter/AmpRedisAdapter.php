<?php declare(strict_types=1);

namespace Resp3\Adapter;

use Amp\Redis\Protocol\RedisError;
use Amp\Redis\Protocol\RedisValue;
use Resp3\Parser;
use Resp3\PushMessage;
use Resp3\RedisException;
use Resp3\VerbatimString;

/**
 * Drop-in replacement for Amp\Redis\Protocol\RespParser that delegates to the
 * C-level Resp3\Parser extension.
 *
 * amphp/redis does not expose a parser interface, so the adapter is duck-typed
 * around the same `push(string $data): void` and `cancel(): void` surface that
 * Amp\Redis\Connection\SocketRedisConnection consumes.
 */
final class AmpRedisAdapter
{
    private readonly Parser $parser;

    /**
     * @param \Closure(\Amp\Redis\Protocol\RedisResponse):void $push
     */
    public function __construct(private readonly \Closure $push)
    {
        $this->parser = new Parser();
    }

    public function push(string $data): void
    {
        $this->parser->feed($data);

        while ($this->parser->hasNext()) {
            $value = $this->parser->next();

            if ($value instanceof RedisException) {
                ($this->push)(new RedisError($value->getMessage()));
                continue;
            }

            // amphp/redis is RESP2 like Fledge; unwrap RESP3 wrappers to plain payloads
            // so the rest of the pipeline keeps seeing scalars and arrays.
            if ($value instanceof VerbatimString) {
                $value = $value->value;
            } elseif ($value instanceof PushMessage) {
                $value = $value->payload;
            }

            ($this->push)(new RedisValue($value));
        }
    }

    public function cancel(): void
    {
        $this->parser->reset();
    }
}

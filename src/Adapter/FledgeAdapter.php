<?php declare(strict_types=1);

namespace Resp3\Adapter;

use Fledge\Async\Redis\Protocol\ParserInterface;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisValue;
use Resp3\Parser;
use Resp3\PushMessage;
use Resp3\RedisException;
use Resp3\VerbatimString;

/**
 * Drop-in replacement for Fledge\Async\Redis\Protocol\RespParser that delegates
 * the actual parsing work to the C-level Resp3\Parser extension.
 *
 * Constructor signature matches the original so it slots in via ParserFactory.
 * Output values are wrapped in Fledge's RedisValue / RedisError so the rest of
 * the SocketRedisConnection pipeline sees identical types.
 */
final class FledgeAdapter implements ParserInterface
{
    private readonly Parser $parser;

    /**
     * @param \Closure(\Fledge\Async\Redis\Protocol\RedisResponse):void $push
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

            // RESP3 wrappers carry no useful semantics for Fledge's RESP2 consumers.
            // Unwrap to the underlying scalar / array so the existing call sites work.
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

<?php declare(strict_types=1);

namespace Resp3\Adapter;

use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\StreamException;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Redis\Connection\RedisConnection;
use Amp\Redis\Connection\RedisConnectionException;
use Amp\Redis\Protocol\RedisResponse;
use Amp\Redis\RedisException;
use Amp\Socket\Socket;
use Revolt\EventLoop;

/**
 * Mirror of Amp\Redis\Connection\SocketRedisConnection that constructs an
 * AmpRedisAdapter (backed by ext-resp3) instead of the bundled RespParser.
 *
 * Body kept identical to the upstream class so any behaviour difference
 * during benchmarking is attributable to the parser swap.
 */
final class AmpRedisConnection implements RedisConnection
{
    private readonly Socket $socket;

    private readonly string $name;

    private readonly ConcurrentIterator $iterator;

    public function __construct(Socket $socket)
    {
        $this->socket = $socket;
        $this->name = $socket->getRemoteAddress()->toString();

        $queue = new Queue();
        $this->iterator = $queue->iterate();

        EventLoop::queue(static function () use ($socket, $queue): void {
            $parser = new AmpRedisAdapter($queue->push(...));

            try {
                while (null !== $chunk = $socket->read()) {
                    $parser->push($chunk);
                }

                $parser->cancel();
                $queue->complete();
            } catch (RedisException $e) {
                $queue->error($e);
            }

            $socket->close();
        });
    }

    public function receive(): ?RedisResponse
    {
        if (!$this->iterator->continue()) {
            return null;
        }

        return $this->iterator->getValue();
    }

    public function send(string ...$args): void
    {
        if ($this->socket->isClosed()) {
            throw new RedisConnectionException('Redis connection already closed');
        }

        $payload = \implode("\r\n", \array_map(fn (string $arg) => '$' . \strlen($arg) . "\r\n" . $arg, $args));
        $payload = '*' . \count($args) . "\r\n{$payload}\r\n";

        try {
            $this->socket->write($payload);
        } catch (StreamException $e) {
            throw new RedisConnectionException($e->getMessage(), 0, $e);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function reference(): void
    {
        if ($this->socket instanceof ResourceStream) {
            $this->socket->reference();
        }
    }

    public function unreference(): void
    {
        if ($this->socket instanceof ResourceStream) {
            $this->socket->unreference();
        }
    }

    public function close(): void
    {
        $this->socket->close();
    }

    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->socket->onClose($onClose);
    }

    public function __destruct()
    {
        $this->close();
    }
}

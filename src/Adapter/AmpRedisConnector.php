<?php declare(strict_types=1);

namespace Resp3\Adapter;

use Amp\Cancellation;
use Amp\Redis\Connection\RedisConnection;
use Amp\Redis\Connection\RedisConnectionException;
use Amp\Redis\Connection\RedisConnector;
use Amp\Socket;
use Amp\Socket\ConnectContext;
use Amp\Socket\SocketConnector;

/**
 * Mirror of Amp\Redis\Connection\SocketRedisConnector that returns an
 * AmpRedisConnection (which uses ext-resp3 for parsing).
 *
 * Pass an instance of this connector to Amp\Redis\createRedisClient() to opt a
 * client into the C-level parser without touching upstream amphp/redis code.
 */
final class AmpRedisConnector implements RedisConnector
{
    private readonly ConnectContext $connectContext;

    public function __construct(
        private readonly string $uri,
        ConnectContext $connectContext,
        private readonly ?SocketConnector $socketConnector = null,
    ) {
        $this->connectContext = $connectContext;
    }

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        try {
            $socketConnector = $this->socketConnector ?? Socket\socketConnector();
            $socket = $socketConnector->connect($this->uri, $this->connectContext, $cancellation);
        } catch (Socket\SocketException $e) {
            throw new RedisConnectionException(
                'Failed to connect to redis instance (' . $this->uri . ')',
                0,
                $e
            );
        }

        return new AmpRedisConnection($socket);
    }
}

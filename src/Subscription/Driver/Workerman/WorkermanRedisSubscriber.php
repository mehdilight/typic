<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver\Workerman;

use Typic\Subscription\PubSub\Redis\RedisSubscriberInterface;
use Typic\Subscription\PubSub\Redis\RespError;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

/**
 * Non-blocking Redis subscriber running on the Workerman event loop.
 *
 * Connects on the first subscribe() and, after a dropped connection, reconnects
 * and resubscribes to every channel it was listening to.
 */
class WorkermanRedisSubscriber implements RedisSubscriberInterface
{
    private ?AsyncTcpConnection $connection = null;

    private bool $connected = false;

    /** @var array<string, true> */
    private array $channels = [];

    /** @var (callable(string, string): void)|null */
    private $messageHandler = null;

    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 6379,
        private ?string $username = null,
        private ?string $password = null,
        private int $reconnectDelay = 1,
    ) {
    }

    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    public function subscribe(string $channel): void
    {
        $this->channels[$channel] = true;

        if ($this->connection === null) {
            // Subscribes to every pending channel once connected
            $this->connect();
        } elseif ($this->connected) {
            $this->connection->send(['SUBSCRIBE', $channel]);
        }
    }

    public function unsubscribe(string $channel): void
    {
        unset($this->channels[$channel]);

        if ($this->connected && $this->connection !== null) {
            $this->connection->send(['UNSUBSCRIBE', $channel]);
        }
    }

    private function connect(): void
    {
        $connection = new AsyncTcpConnection("tcp://{$this->host}:{$this->port}");
        $connection->protocol = RespProtocol::class;

        $connection->onConnect = function (AsyncTcpConnection $connection): void {
            $this->connected = true;

            if ($this->password !== null) {
                $connection->send($this->username !== null
                    ? ['AUTH', $this->username, $this->password]
                    : ['AUTH', $this->password]);
            }

            if ($this->channels !== []) {
                $connection->send(['SUBSCRIBE', ...array_keys($this->channels)]);
            }
        };

        $connection->onMessage = function (AsyncTcpConnection $connection, mixed $reply): void {
            if ($reply instanceof RespError) {
                Worker::safeEcho("Typic Redis subscriber error: {$reply->message}\n");
                return;
            }

            if (is_array($reply) && ($reply[0] ?? null) === 'message' && $this->messageHandler !== null
                && is_string($reply[1] ?? null) && is_string($reply[2] ?? null)) {
                ($this->messageHandler)($reply[1], $reply[2]);
            }
        };

        $connection->onError = function (AsyncTcpConnection $connection, int $code, string $message): void {
            Worker::safeEcho("Typic Redis subscriber connection error: {$message}\n");
        };

        $connection->onClose = function (AsyncTcpConnection $connection): void {
            $this->connected = false;
            $connection->reconnect($this->reconnectDelay);
        };

        $this->connection = $connection;
        $connection->connect();
    }
}

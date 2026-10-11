<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver\Workerman;

use RuntimeException;
use Throwable;
use Typic\Subscription\Driver\WebSocketConnectionInterface;
use Typic\Subscription\Driver\WebSocketServerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

class WorkermanDriver implements WebSocketServerInterface
{
    private ?Worker $worker = null;

    /** @var (callable(WebSocketConnectionInterface): void)|null */
    private $onConnectCallback = null;

    /** @var (callable(WebSocketConnectionInterface, string): void)|null */
    private $onMessageCallback = null;

    /** @var (callable(WebSocketConnectionInterface): void)|null */
    private $onCloseCallback = null;

    /** @var (callable(WebSocketConnectionInterface, Throwable): void)|null */
    private $onErrorCallback = null;

    /** @var array<int, WorkermanConnection> */
    private array $connections = [];

    public function __construct(
        private string $host = '0.0.0.0',
        private int $port = 8080,
        private int $workerCount = 1,
        private string $workerName = 'TypicSubscriptionServer',
    ) {
    }

    public function onConnect(callable $callback): self
    {
        $this->onConnectCallback = $callback;
        return $this;
    }

    public function onMessage(callable $callback): self
    {
        $this->onMessageCallback = $callback;
        return $this;
    }

    public function onClose(callable $callback): self
    {
        $this->onCloseCallback = $callback;
        return $this;
    }

    public function onError(callable $callback): self
    {
        $this->onErrorCallback = $callback;
        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getWorker(): Worker
    {
        if ($this->worker === null) {
            $this->initWorker();
        }
        /** @var Worker */
        return $this->worker;
    }

    private function initWorker(): void
    {
        $this->worker = new Worker("websocket://{$this->host}:{$this->port}");
        $this->worker->count = $this->workerCount;
        $this->worker->name = $this->workerName;

        $this->worker->onWebSocketConnect = function (TcpConnection $connection): void {
            // Negotiate the graphql-transport-ws subprotocol
            $connection->headers = [
                'Sec-WebSocket-Protocol: graphql-transport-ws',
            ];

            $wrapped = new WorkermanConnection($connection);
            $this->connections[$connection->id] = $wrapped;

            if ($this->onConnectCallback !== null) {
                ($this->onConnectCallback)($wrapped);
            }
        };

        $this->worker->onMessage = function (TcpConnection $connection, mixed $data): void {
            $wrapped = $this->connections[$connection->id] ?? new WorkermanConnection($connection);
            if ($this->onMessageCallback !== null && is_string($data)) {
                ($this->onMessageCallback)($wrapped, $data);
            }
        };

        $this->worker->onClose = function (TcpConnection $connection): void {
            $wrapped = $this->connections[$connection->id] ?? new WorkermanConnection($connection);
            unset($this->connections[$connection->id]);

            if ($this->onCloseCallback !== null) {
                ($this->onCloseCallback)($wrapped);
            }
        };

        $this->worker->onError = function (TcpConnection $connection, int $code, string $msg): void {
            $wrapped = $this->connections[$connection->id] ?? new WorkermanConnection($connection);
            if ($this->onErrorCallback !== null) {
                ($this->onErrorCallback)($wrapped, new RuntimeException($msg, $code));
            }
        };
    }

    public function start(): void
    {
        if ($this->worker === null) {
            $this->initWorker();
        }

        Worker::runAll();
    }

    public function stop(): void
    {
        Worker::stopAll();
    }
}

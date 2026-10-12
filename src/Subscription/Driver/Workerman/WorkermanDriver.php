<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver\Workerman;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;
use Typic\Subscription\Driver\HttpServerInterface;
use Typic\Subscription\Driver\WebSocketConnectionInterface;
use Typic\Subscription\Driver\WebSocketServerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;
use Workerman\Worker;

/**
 * Serves HTTP and graphql-transport-ws WebSocket connections on one port and one event loop.
 *
 * With the default RedisPubSub any workerCount works. Keep it at 1 with InMemoryPubSub:
 * each worker is a separate process, so its events never reach the others.
 */
class WorkermanDriver implements WebSocketServerInterface, HttpServerInterface
{
    private ?Worker $worker = null;

    /** @var (callable(ServerRequestInterface): ResponseInterface)|null */
    private $requestHandler = null;

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

    public function onRequest(callable $handler): self
    {
        $this->requestHandler = $handler;
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
        $this->worker = new Worker("tcp://{$this->host}:{$this->port}");
        $this->worker->protocol = HttpWebSocketProtocol::class;
        $this->worker->count = $this->workerCount;
        $this->worker->name = $this->workerName;

        $this->worker->onWebSocketConnect = function (TcpConnection $connection, WorkermanRequest $request): void {
            // Negotiate the graphql-transport-ws subprotocol
            $connection->headers = [
                'Sec-WebSocket-Protocol: graphql-transport-ws',
            ];

            // Keep the upgrade request, so middleware can read its headers and cookies
            $wrapped = new WorkermanConnection($connection, $this->toPsrRequest($request, $connection));
            $this->connections[$connection->id] = $wrapped;

            if ($this->onConnectCallback !== null) {
                ($this->onConnectCallback)($wrapped);
            }
        };

        $this->worker->onMessage = function (TcpConnection $connection, mixed $data): void {
            if ($data instanceof WorkermanRequest) {
                $response = $this->handleHttpRequest($data, $connection);
                if ($this->keepsAlive($data)) {
                    $connection->send($response);
                } else {
                    $connection->close($response->withHeader('Connection', 'close'));
                }
                return;
            }

            $wrapped = $this->connections[$connection->id] ?? null;
            if ($wrapped !== null && $this->onMessageCallback !== null && is_string($data)) {
                ($this->onMessageCallback)($wrapped, $data);
            }
        };

        $this->worker->onClose = function (TcpConnection $connection): void {
            // Plain HTTP connections never registered, so there is nothing to clean up
            $wrapped = $this->connections[$connection->id] ?? null;
            if ($wrapped === null) {
                return;
            }
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

    /**
     * Convert a Workerman HTTP request to PSR-7, run the registered handler, and convert the response back.
     */
    public function handleHttpRequest(WorkermanRequest $request, ?TcpConnection $connection = null): WorkermanResponse
    {
        if ($this->requestHandler === null) {
            return new WorkermanResponse(426, ['Upgrade' => 'websocket'], 'Upgrade Required');
        }

        try {
            $response = ($this->requestHandler)($this->toPsrRequest($request, $connection));
        } catch (Throwable $e) {
            Worker::safeEcho((string) $e . "\n");
            return new WorkermanResponse(500, ['Content-Type' => 'text/plain'], 'Internal Server Error');
        }

        return new WorkermanResponse(
            $response->getStatusCode(),
            $response->getHeaders(),
            (string) $response->getBody(),
        );
    }

    /**
     * HTTP/1.1 connections stay open unless the client asks to close; HTTP/1.0 ones only when asked to stay open.
     */
    private function keepsAlive(WorkermanRequest $request): bool
    {
        $header = $request->header('connection');
        $connection = is_string($header) ? strtolower($header) : '';

        return $request->protocolVersion() === '1.0'
            ? $connection === 'keep-alive'
            : $connection !== 'close';
    }

    private function toPsrRequest(WorkermanRequest $request, ?TcpConnection $connection): ServerRequestInterface
    {
        $host = $request->host() ?? "{$this->host}:{$this->port}";

        /** @var array<string, string> $headers */
        $headers = $request->header();
        /** @var array<string, mixed> $queryParams */
        $queryParams = $request->get();
        /** @var array<string, string> $cookies */
        $cookies = $request->cookie();
        /** @var array<string, mixed> $post */
        $post = $request->post();

        $serverParams = [];
        if ($connection !== null) {
            $serverParams['REMOTE_ADDR'] = $connection->getRemoteIp();
            $serverParams['REMOTE_PORT'] = $connection->getRemotePort();
        }

        return (new ServerRequest(
            $request->method(),
            "http://{$host}{$request->uri()}",
            $headers,
            $request->rawBody(),
            $request->protocolVersion(),
            $serverParams,
        ))
            ->withQueryParams($queryParams)
            ->withCookieParams($cookies)
            ->withParsedBody($post !== [] ? $post : null);
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

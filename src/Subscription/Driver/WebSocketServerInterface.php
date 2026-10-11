<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver;

use Throwable;

interface WebSocketServerInterface
{
    /**
     * Register a callback triggered when a new WebSocket connection is established and handshaken.
     *
     * @param callable(WebSocketConnectionInterface $connection): void $callback
     * @return $this
     */
    public function onConnect(callable $callback): self;

    /**
     * Register a callback triggered when a text frame message is received from a client.
     *
     * @param callable(WebSocketConnectionInterface $connection, string $message): void $callback
     * @return $this
     */
    public function onMessage(callable $callback): self;

    /**
     * Register a callback triggered when a WebSocket connection is closed.
     *
     * @param callable(WebSocketConnectionInterface $connection): void $callback
     * @return $this
     */
    public function onClose(callable $callback): self;

    /**
     * Register a callback triggered when a connection error occurs.
     *
     * @param callable(WebSocketConnectionInterface $connection, Throwable $error): void $callback
     * @return $this
     */
    public function onError(callable $callback): self;

    /**
     * Start the server event loop.
     */
    public function start(): void;

    /**
     * Stop the server.
     */
    public function stop(): void;

    public function getHost(): string;

    public function getPort(): int;
}

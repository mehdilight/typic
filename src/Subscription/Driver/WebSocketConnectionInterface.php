<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver;

interface WebSocketConnectionInterface
{
    /**
     * Unique identifier for the connection within the server session.
     */
    public function getId(): string;

    /**
     * Send a raw string frame down the WebSocket connection to the client.
     */
    public function send(string $data): void;

    /**
     * Close the WebSocket connection.
     */
    public function close(int $code = 1000, string $reason = ''): void;
}

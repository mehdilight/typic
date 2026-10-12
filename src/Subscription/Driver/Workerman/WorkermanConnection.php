<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver\Workerman;

use Psr\Http\Message\ServerRequestInterface;
use Typic\Subscription\Driver\HandshakeAwareInterface;
use Typic\Subscription\Driver\WebSocketConnectionInterface;
use Workerman\Connection\TcpConnection;

class WorkermanConnection implements WebSocketConnectionInterface, HandshakeAwareInterface
{
    public function __construct(
        private TcpConnection $connection,
        private ?ServerRequestInterface $handshakeRequest = null,
    ) {
    }

    public function getHandshakeRequest(): ?ServerRequestInterface
    {
        return $this->handshakeRequest;
    }

    public function getId(): string
    {
        return (string) $this->connection->id;
    }

    public function send(string $data): void
    {
        $this->connection->send($data);
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        // A WebSocket close frame contains a two-byte status followed by a UTF-8 reason.
        $this->connection->websocketType = "\x88";
        $this->connection->close(pack('n', $code) . mb_strcut($reason, 0, 123, 'UTF-8'));
    }

    public function getTcpConnection(): TcpConnection
    {
        return $this->connection;
    }
}

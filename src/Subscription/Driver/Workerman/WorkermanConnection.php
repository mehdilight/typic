<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver\Workerman;

use Typic\Subscription\Driver\WebSocketConnectionInterface;
use Workerman\Connection\TcpConnection;

class WorkermanConnection implements WebSocketConnectionInterface
{
    public function __construct(
        private TcpConnection $connection,
    ) {
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
        $this->connection->close($reason);
    }

    public function getTcpConnection(): TcpConnection
    {
        return $this->connection;
    }
}

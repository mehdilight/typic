<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver\Workerman;

use Typic\Subscription\PubSub\Redis\Resp;
use Workerman\Connection\TcpConnection;

/**
 * Workerman framing for Redis replies.
 */
class RespProtocol
{
    public static function input(string $buffer, TcpConnection $connection): int
    {
        return Resp::parse($buffer)[1] ?? 0;
    }

    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        return Resp::parse($buffer)[0] ?? null;
    }

    /**
     * @param list<string> $command
     */
    public static function encode(array $command, TcpConnection $connection): string
    {
        return Resp::encode($command);
    }
}

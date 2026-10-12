<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver\Workerman;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Websocket;

/**
 * Serves HTTP and WebSocket on the same port.
 *
 * Every connection starts as HTTP. When a request asks to upgrade to WebSocket, the
 * connection is handed over to Workerman's Websocket protocol for the rest of its life.
 */
class HttpWebSocketProtocol
{
    public static function input(string $buffer, TcpConnection $connection): int
    {
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd !== false && self::isWebSocketUpgrade(substr($buffer, 0, $headerEnd))) {
            $connection->protocol = Websocket::class;
            return Websocket::input($buffer, $connection);
        }

        return Http::input($buffer, $connection);
    }

    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        return Http::decode($buffer, $connection);
    }

    public static function encode(mixed $response, TcpConnection $connection): string
    {
        return Http::encode($response, $connection);
    }

    private static function isWebSocketUpgrade(string $header): bool
    {
        return preg_match('/^Upgrade:\s*websocket\s*$/im', $header) === 1;
    }
}

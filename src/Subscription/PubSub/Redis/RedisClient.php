<?php

declare(strict_types=1);

namespace Typic\Subscription\PubSub\Redis;

use RuntimeException;

/**
 * Blocking Redis client for request/reply commands such as PUBLISH.
 *
 * Works in any PHP process (event loop, PHP-FPM, CLI) without ext-redis.
 * The connection is opened lazily and kept for the lifetime of the process.
 */
class RedisClient
{
    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';

    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 6379,
        private ?string $username = null,
        private ?string $password = null,
        private float $timeout = 2.0,
    ) {
    }

    /**
     * Send a command and return its reply.
     *
     * @throws RedisConnectionException when Redis cannot be reached
     * @throws RuntimeException when Redis replies with an error
     */
    public function command(string ...$args): mixed
    {
        try {
            return $this->roundTrip(array_values($args));
        } catch (RedisConnectionException) {
            // The kept-alive connection may have been dropped by Redis; retry once on a fresh one
            $this->disconnect();
            return $this->roundTrip(array_values($args));
        }
    }

    public function disconnect(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->buffer = '';
    }

    /**
     * @param list<string> $args
     */
    private function roundTrip(array $args): mixed
    {
        $socket = $this->socket ?? $this->connect();
        $this->write($socket, Resp::encode($args));
        $reply = $this->readReply($socket);

        if ($reply instanceof RespError) {
            throw new RuntimeException("Redis error: {$reply->message}");
        }

        return $reply;
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new RedisConnectionException("Could not connect to Redis at {$this->host}:{$this->port}: {$errstr}");
        }

        $seconds = (int) $this->timeout;
        stream_set_timeout($socket, $seconds, (int) (($this->timeout - $seconds) * 1_000_000));
        $this->socket = $socket;

        if ($this->password !== null) {
            $auth = $this->username !== null ? ['AUTH', $this->username, $this->password] : ['AUTH', $this->password];
            $this->write($socket, Resp::encode($auth));
            $reply = $this->readReply($socket);
            if ($reply instanceof RespError) {
                $this->disconnect();
                throw new RuntimeException("Redis authentication failed: {$reply->message}");
            }
        }

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($socket, $data);
            if ($written === false || $written === 0) {
                throw new RedisConnectionException('Connection to Redis lost while writing');
            }
            $data = substr($data, $written);
        }
    }

    /**
     * @param resource $socket
     */
    private function readReply($socket): mixed
    {
        while (($parsed = Resp::parse($this->buffer)) === null) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                throw new RedisConnectionException('Connection to Redis lost while reading');
            }
            $this->buffer .= $chunk;
        }

        $this->buffer = substr($this->buffer, $parsed[1]);

        return $parsed[0];
    }
}

<?php

declare(strict_types=1);

namespace Typic\Tests\Support;

use Typic\Subscription\Driver\WebSocketServerInterface;

/**
 * A driver that never listens: tests talk to the protocol handler directly.
 */
final class NullDriver implements WebSocketServerInterface
{
    public function onConnect(callable $callback): self
    {
        return $this;
    }

    public function onMessage(callable $callback): self
    {
        return $this;
    }

    public function onClose(callable $callback): self
    {
        return $this;
    }

    public function onError(callable $callback): self
    {
        return $this;
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function getHost(): string
    {
        return '127.0.0.1';
    }

    public function getPort(): int
    {
        return 0;
    }
}

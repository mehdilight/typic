<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Psr\Http\Message\ServerRequestInterface;
use Typic\Subscription\Driver\HandshakeAwareInterface;
use Typic\Subscription\Driver\WebSocketConnectionInterface;

final class HandshakeConnection implements WebSocketConnectionInterface, HandshakeAwareInterface
{
    /** @var list<array<string, mixed>> */
    public array $received = [];

    public function __construct(private ?ServerRequestInterface $handshake = null)
    {
    }

    public function getId(): string
    {
        return spl_object_hash($this);
    }

    public function send(string $data): void
    {
        /** @var array<string, mixed> $message */
        $message = json_decode($data, true);
        $this->received[] = $message;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
    }

    public function getHandshakeRequest(): ?ServerRequestInterface
    {
        return $this->handshake;
    }
}

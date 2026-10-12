<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Implemented by WebSocket connections that keep the HTTP request that opened them, so
 * resolver middleware can read its headers and cookies through ResolverCall::request().
 */
interface HandshakeAwareInterface
{
    public function getHandshakeRequest(): ?ServerRequestInterface;
}

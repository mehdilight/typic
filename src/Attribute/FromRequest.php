<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

/**
 * Injects an attribute of the HTTP request, set by PSR-15 middleware with
 * $request->withAttribute($name, $value). Over WebSocket, the handshake request is used.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class FromRequest
{
    public function __construct(
        public string $name,
    ) {
    }
}

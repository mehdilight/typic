<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;

final class TrimArgs implements ResolverMiddleware
{
    public function handle(ResolverCall $call, callable $next): mixed
    {
        $args = array_map(static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $call->args());

        return $next($call->withArgs($args));
    }
}

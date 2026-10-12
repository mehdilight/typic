<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;

final class Uppercase implements ResolverMiddleware
{
    public function handle(ResolverCall $call, callable $next): mixed
    {
        $result = $next($call);

        return is_string($result) ? strtoupper($result) : $result;
    }
}

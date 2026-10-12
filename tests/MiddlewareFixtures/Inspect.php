<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;

final class Inspect implements ResolverMiddleware
{
    public static ?ResolverCall $last = null;

    public function handle(ResolverCall $call, callable $next): mixed
    {
        self::$last = $call;

        return $next($call);
    }
}

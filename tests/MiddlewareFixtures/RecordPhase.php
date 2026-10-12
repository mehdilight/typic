<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;

final class RecordPhase implements ResolverMiddleware
{
    public function handle(ResolverCall $call, callable $next): mixed
    {
        Trace::$log[] = $call->subscribing() ? 'subscribe' : 'event';

        return $next($call);
    }
}

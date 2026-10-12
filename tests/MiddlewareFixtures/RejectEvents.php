<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use GraphQL\Error\UserError;
use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;

final class RejectEvents implements ResolverMiddleware
{
    public function handle(ResolverCall $call, callable $next): mixed
    {
        if (!$call->subscribing()) {
            throw new UserError('Event blocked');
        }

        return $next($call);
    }
}

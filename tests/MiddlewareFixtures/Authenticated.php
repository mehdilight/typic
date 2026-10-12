<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use GraphQL\Error\UserError;
use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;

final class Authenticated implements ResolverMiddleware
{
    private const TOKENS = ['alice-token' => 'alice', 'bob-token' => 'bob'];

    public function handle(ResolverCall $call, callable $next): mixed
    {
        // Over HTTP the token is a header; WebSocket clients usually send it in connection_init
        $header = $call->request()?->getHeaderLine('Authorization') ?: ($call->connectionParams()['Authorization'] ?? '');
        $token = substr(is_string($header) ? $header : '', strlen('Bearer '));
        $user = self::TOKENS[$token] ?? throw new UserError('You must be logged in.');

        return $next($call->with('user', $user));
    }
}

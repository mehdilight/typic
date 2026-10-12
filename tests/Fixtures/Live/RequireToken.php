<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Live;

use GraphQL\Error\UserError;
use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;

final class RequireToken implements ResolverMiddleware
{
    public function __construct(private string $token)
    {
    }

    public function handle(ResolverCall $call, callable $next): mixed
    {
        if ($call->request()?->getHeaderLine('Authorization') !== "Bearer {$this->token}") {
            throw new UserError('Unauthorized');
        }

        return $next($call);
    }
}

<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use GraphQL\Error\UserError;
use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;

final class Can implements ResolverMiddleware
{
    private const ABILITIES = ['alice' => ['admin'], 'bob' => []];

    public function __construct(private string $ability)
    {
    }

    public function handle(ResolverCall $call, callable $next): mixed
    {
        $user = $call->get('user');
        if (!in_array($this->ability, self::ABILITIES[$user] ?? [], true)) {
            throw new UserError("You need the '{$this->ability}' ability.");
        }

        return $next($call);
    }
}

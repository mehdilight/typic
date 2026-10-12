<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;

final class ReturnEarly implements ResolverMiddleware
{
    public function __construct(private mixed $value)
    {
    }

    public function handle(ResolverCall $call, callable $next): mixed
    {
        return $this->value;
    }
}

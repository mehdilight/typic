<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;

final class Record implements ResolverMiddleware
{
    public function __construct(private string $name)
    {
    }

    public function handle(ResolverCall $call, callable $next): mixed
    {
        Trace::$log[] = "before {$this->name}";
        $result = $next($call);
        Trace::$log[] = "after {$this->name}";

        return $result;
    }
}

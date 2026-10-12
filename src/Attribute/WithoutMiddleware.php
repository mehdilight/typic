<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;
use Typic\Middleware\ResolverMiddleware;

/**
 * Skips middleware of these classes (or of every class in these groups) that the method
 * would get from its class or from global middleware. The method's own #[Middleware] still runs.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class WithoutMiddleware
{
    /** @var list<string> */
    public array $middleware;

    /**
     * @param class-string<ResolverMiddleware>|string ...$middleware Middleware classes or group names
     */
    public function __construct(string ...$middleware)
    {
        $this->middleware = array_values($middleware);
    }
}

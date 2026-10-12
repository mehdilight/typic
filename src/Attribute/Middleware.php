<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;
use Typic\Middleware\ResolverMiddleware;

/**
 * Runs middleware around a resolver method, or around every field of a class.
 *
 * Each entry is a middleware instance, a middleware class name, or the name of a group
 * registered with TypicBuilder::withMiddlewareGroup(). Entries run in the order written,
 * after global middleware and, on a method, after the class's middleware.
 *
 *     #[Middleware(Authenticated::class, new Can('posts.create'))]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware
{
    /** @var list<ResolverMiddleware|string> */
    public array $middleware;

    public function __construct(ResolverMiddleware|string ...$middleware)
    {
        $this->middleware = array_values($middleware);
    }
}

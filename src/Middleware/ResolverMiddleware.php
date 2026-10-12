<?php

declare(strict_types=1);

namespace Typic\Middleware;

/**
 * Runs around a resolver: check something before it, change what it returns, or stop it.
 *
 *     final class Authenticated implements ResolverMiddleware
 *     {
 *         public function handle(ResolverCall $call, callable $next): mixed
 *         {
 *             $user = $this->users->fromToken($call->request()?->getHeaderLine('Authorization'))
 *                 ?? throw new UserError('You must be logged in.');
 *
 *             return $next($call->with('user', $user));
 *         }
 *     }
 *
 * Attach it with #[Middleware(Authenticated::class)] on a resolver method or class, or to
 * every field with TypicBuilder::withResolverMiddleware().
 */
interface ResolverMiddleware
{
    /**
     * Call $next($call) to continue to the next middleware and finally the resolver, and
     * return what it returns (changed if you like). Throw, or return without calling
     * $next, to stop the resolver from running.
     *
     * @param callable(ResolverCall): mixed $next
     */
    public function handle(ResolverCall $call, callable $next): mixed;
}

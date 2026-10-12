<?php

declare(strict_types=1);

namespace Typic\Server;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs PSR-15 middleware in order, first outermost, then the final handler.
 *
 * @internal
 */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function __construct(
        private array $middleware,
        private $handler,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->middleware === []) {
            return ($this->handler)($request);
        }

        [$first, $rest] = [$this->middleware[0], array_slice($this->middleware, 1)];

        return $first->process($request, new self($rest, $this->handler));
    }
}

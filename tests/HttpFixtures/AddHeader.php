<?php

declare(strict_types=1);

namespace Typic\Tests\HttpFixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Appends its name to X-Trace on the way out, to show the order middleware runs in.
 */
final class AddHeader implements MiddlewareInterface
{
    public function __construct(private string $name = 'default')
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withAddedHeader('X-Trace', $this->name);
    }
}

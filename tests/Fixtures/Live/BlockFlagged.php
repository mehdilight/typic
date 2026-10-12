<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Live;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses any request, WebSocket handshakes included, that carries "X-Block: yes".
 */
final class BlockFlagged implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $request->getHeaderLine('X-Block') === 'yes'
            ? new Response(403, ['Content-Type' => 'text/plain'], 'Blocked')
            : $handler->handle($request);
    }
}

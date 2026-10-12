<?php

declare(strict_types=1);

namespace Typic\Tests\HttpFixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns "Authorization: Bearer <name>" into a "user" request attribute; refuses "Bearer banned".
 */
final class TokenAuth implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = substr($request->getHeaderLine('Authorization'), strlen('Bearer '));
        if ($token === 'banned') {
            return new Response(401, ['Content-Type' => 'text/plain'], 'Go away');
        }

        return $handler->handle($token !== '' ? $request->withAttribute('user', $token) : $request);
    }
}

<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Implemented by drivers that can also serve plain HTTP requests from their event loop.
 *
 * Serving queries, mutations and subscriptions from one process lets them share an
 * in-memory Pub/Sub: a mutation's publish() reaches the subscribers directly.
 */
interface HttpServerInterface
{
    /**
     * Register the handler for every non-WebSocket HTTP request.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     * @return $this
     */
    public function onRequest(callable $handler): self;

    /**
     * Register what checks each WebSocket upgrade request: it returns the request to accept
     * the upgrade (resolvers then see it as the connection's request), or a response to refuse it.
     *
     * @param callable(ServerRequestInterface): (ServerRequestInterface|ResponseInterface) $handler
     * @return $this
     */
    public function onHandshake(callable $handler): self;
}

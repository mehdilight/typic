<?php

declare(strict_types=1);

namespace Typic\Subscription;

use GraphQL\Type\Schema;
use Psr\Container\ContainerInterface;
use Typic\Subscription\Driver\WebSocketConnectionInterface;
use Typic\Subscription\Driver\WebSocketServerInterface;
use Typic\Subscription\Driver\Workerman\WorkermanDriver;
use Typic\Subscription\Protocol\GraphqlWsHandler;
use Typic\Subscription\PubSub\PubSubInterface;
use Typic\Subscription\PubSub\RedisPubSub;

class SubscriptionServer
{
    private WebSocketServerInterface $driver;
    private PubSubInterface $pubSub;
    private GraphqlWsHandler $handler;

    public function __construct(
        private Schema $schema,
        private SubscriptionRegistry $registry,
        ?WebSocketServerInterface $driver = null,
        ?PubSubInterface $pubSub = null,
        private ?ContainerInterface $container = null,
        ?GraphqlWsHandler $handler = null,
    ) {
        $this->driver = $driver ?? new WorkermanDriver();
        $this->pubSub = $pubSub ?? RedisPubSub::fromEnvironment();

        $this->handler = $handler ?? new GraphqlWsHandler(
            schema: $this->schema,
            registry: $this->registry,
            pubSub: $this->pubSub,
            container: $this->container,
        );

        $this->bindDriverEvents();
    }

    private function bindDriverEvents(): void
    {
        $this->driver->onConnect(function (WebSocketConnectionInterface $conn): void {
            $this->handler->handleConnect($conn);
        });

        $this->driver->onMessage(function (WebSocketConnectionInterface $conn, string $message): void {
            $this->handler->handleMessage($conn, $message);
        });

        $this->driver->onClose(function (WebSocketConnectionInterface $conn): void {
            $this->handler->handleClose($conn);
        });
    }

    /**
     * Start the real-time WebSocket subscription server.
     */
    public function start(): void
    {
        $this->driver->start();
    }

    /**
     * Stop the subscription server.
     */
    public function stop(): void
    {
        $this->driver->stop();
    }

    public function getDriver(): WebSocketServerInterface
    {
        return $this->driver;
    }

    public function getPubSub(): PubSubInterface
    {
        return $this->pubSub;
    }

    public function getHandler(): GraphqlWsHandler
    {
        return $this->handler;
    }

    public function getProtocolHandler(): GraphqlWsHandler
    {
        return $this->handler;
    }

    public function getRegistry(): SubscriptionRegistry
    {
        return $this->registry;
    }

    public function getSchema(): Schema
    {
        return $this->schema;
    }
}

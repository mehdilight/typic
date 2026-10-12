<?php

declare(strict_types=1);

namespace Typic\Subscription\PubSub\Redis;

/**
 * Receives Redis Pub/Sub messages without blocking the server's event loop.
 *
 * Implementations are tied to an event loop; WorkermanRedisSubscriber is the default.
 */
interface RedisSubscriberInterface
{
    /**
     * @param callable(string $channel, string $message): void $handler
     */
    public function onMessage(callable $handler): void;

    public function subscribe(string $channel): void;

    public function unsubscribe(string $channel): void;
}

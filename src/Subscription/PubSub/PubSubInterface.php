<?php

declare(strict_types=1);

namespace Typic\Subscription\PubSub;

interface PubSubInterface
{
    /**
     * Publish an event payload to a topic.
     */
    public function publish(string $topic, mixed $payload): void;

    /**
     * Subscribe a listener callback to a topic.
     *
     * @param callable(mixed $payload): void $listener
     * @return string Unique subscription token
     */
    public function subscribe(string $topic, callable $listener): string;

    /**
     * Unsubscribe a listener by its subscription token.
     */
    public function unsubscribe(string $subscriptionId): void;
}

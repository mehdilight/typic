<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Live;

use Typic\Attribute\Mutation;
use Typic\Attribute\Query;
use Typic\Attribute\Subscription;
use Typic\Subscription\PubSub\PubSubInterface;

class LiveResolver
{
    private static int $nextId = 1;

    #[Query]
    public function ping(): string
    {
        return 'pong';
    }

    #[Mutation]
    public function sendMessage(string $text, PubSubInterface $pubSub): LiveMessage
    {
        $message = new LiveMessage(self::$nextId++, $text);
        $pubSub->publish('MESSAGE_SENT', $message);
        return $message;
    }

    #[Subscription(name: 'messageSent', type: LiveMessage::class, topic: 'MESSAGE_SENT')]
    public function messageSent(): bool
    {
        return true;
    }
}

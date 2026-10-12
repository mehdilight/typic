<?php

declare(strict_types=1);

namespace Typic\Tests\SubscriptionFixtures;

use RuntimeException;
use Typic\Attribute\Subscription;
use Typic\Tests\MiddlewareFixtures\Secret;

class CrashingSubscription
{
    #[Subscription(name: 'crashing', type: Secret::class, topic: 'CRASH')]
    public function crashing(): bool
    {
        throw new RuntimeException('Subscriber exploded');
    }
}

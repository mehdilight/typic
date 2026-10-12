<?php

declare(strict_types=1);

namespace Typic\Tests\SubscriptionFixtures;

use Typic\Attribute\Subscription;
use Typic\Tests\MiddlewareFixtures\Secret;

class OpenSubscription
{
    #[Subscription(name: 'secrets', type: Secret::class, topic: 'SECRETS')]
    public function secrets(): bool
    {
        return true;
    }
}

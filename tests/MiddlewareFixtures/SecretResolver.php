<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\FromCall;
use Typic\Attribute\Middleware;
use Typic\Attribute\Subscription;

class SecretResolver
{
    #[Subscription(name: 'secretAdded', type: Secret::class, topic: 'SECRET_ADDED')]
    #[Middleware(new RecordPhase(), Authenticated::class)]
    public function secretAdded(Secret $payload, #[FromCall('user')] string $user): bool
    {
        Trace::$log[] = "resolver for {$user}";

        return $payload->owner === $user;
    }

    #[Subscription(name: 'blocked', type: Secret::class, topic: 'SECRET_ADDED')]
    #[Middleware(new RejectEvents())]
    public function blocked(): bool
    {
        return true;
    }
}

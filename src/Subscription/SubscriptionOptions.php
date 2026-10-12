<?php

declare(strict_types=1);

namespace Typic\Subscription;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

/** Connection lifecycle settings for graphql-transport-ws. */
final class SubscriptionOptions
{
    /** @var (callable(array<string, mixed>, ?ServerRequestInterface): bool)|null */
    public readonly mixed $onConnectionInit;

    /** @param (callable(array<string, mixed>, ?ServerRequestInterface): bool)|null $onConnectionInit */
    public function __construct(
        public readonly float $keepAlive = 30,
        public readonly float $connectionInitTimeout = 5,
        public readonly int $maxSubscriptionsPerConnection = 100,
        ?callable $onConnectionInit = null,
    ) {
        if (!is_finite($keepAlive) || $keepAlive < 0 || !is_finite($connectionInitTimeout) || $connectionInitTimeout <= 0 || $maxSubscriptionsPerConnection < 1) {
            throw new InvalidArgumentException('Subscription timeouts must be positive (keepAlive may be 0 to disable it), and the subscription limit must be at least 1.');
        }
        $this->onConnectionInit = $onConnectionInit;
    }
}

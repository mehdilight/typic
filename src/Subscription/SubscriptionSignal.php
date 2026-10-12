<?php

declare(strict_types=1);

namespace Typic\Subscription;

use RuntimeException;

/**
 * Thrown by a subscription field's resolver to tell GraphqlWsHandler how execution ended:
 * the subscription was accepted (its middleware passed), or the event was filtered out.
 *
 * @internal
 */
final class SubscriptionSignal extends RuntimeException
{
    private function __construct(public readonly bool $accepted)
    {
        parent::__construct($accepted ? 'Subscription accepted' : 'Event filtered out');
    }

    public static function accepted(): self
    {
        return new self(true);
    }

    public static function filtered(): self
    {
        return new self(false);
    }
}

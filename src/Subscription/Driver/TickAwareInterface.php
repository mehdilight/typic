<?php

declare(strict_types=1);

namespace Typic\Subscription\Driver;

/** Drivers call the callback once a second from their event loop. */
interface TickAwareInterface
{
    /** @param callable(): void $callback */
    public function onTick(callable $callback): self;
}

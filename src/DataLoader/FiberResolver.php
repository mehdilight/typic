<?php

declare(strict_types=1);

namespace Typic\DataLoader;

use Fiber;
use GraphQL\Deferred;
use LogicException;
use WeakMap;

/**
 * Runs a resolver in a Fiber so DataLoader::get() can pause it until the batch is loaded.
 *
 * graphql-php resolves a whole level of the query before running Deferred callbacks. Every
 * resolver on that level starts, queues its key in get() and pauses; the first Deferred then
 * runs the batch once and each paused resolver resumes with its value.
 */
final class FiberResolver
{
    /** @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, true>|null */
    private static ?WeakMap $fibers = null;

    /**
     * @param callable(): mixed $resolver
     * @return mixed The resolver's value, or a Deferred when it is waiting on a DataLoader
     */
    public static function run(callable $resolver): mixed
    {
        $fiber = new Fiber($resolver);
        self::fibers()[$fiber] = true;

        return self::settle($fiber, $fiber->start());
    }

    /**
     * Whether the current code runs inside a resolver started by run().
     */
    public static function isRunning(): bool
    {
        $current = Fiber::getCurrent();

        return $current !== null && isset(self::fibers()[$current]);
    }

    /**
     * @param Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    private static function settle(Fiber $fiber, mixed $waitingOn): mixed
    {
        if ($fiber->isTerminated()) {
            return $fiber->getReturn();
        }

        if (!$waitingOn instanceof DataLoader) {
            throw new LogicException('A resolver paused its Fiber for something other than a DataLoader.');
        }

        return new Deferred(static function () use ($fiber, $waitingOn): mixed {
            $waitingOn->dispatch();

            return self::settle($fiber, $fiber->resume());
        });
    }

    /**
     * @return WeakMap<Fiber<mixed, mixed, mixed, mixed>, true>
     */
    private static function fibers(): WeakMap
    {
        return self::$fibers ??= new WeakMap();
    }
}

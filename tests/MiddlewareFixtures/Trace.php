<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

/**
 * Records what ran, in order, so tests can assert the shape of the middleware onion.
 */
final class Trace
{
    /** @var list<string> */
    public static array $log = [];
}

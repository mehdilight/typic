<?php

declare(strict_types=1);

namespace Typic\Tests\ScalarFixtures;

/**
 * Stands in for a third-party class (like ramsey/uuid) that cannot carry Typic attributes.
 */
final class Uuid
{
    public function __construct(public readonly string $value)
    {
    }
}

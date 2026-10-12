<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Animals;

/**
 * Implements Named but is not a GraphQL type.
 */
final class Ghost implements Named
{
    public function name(): string
    {
        return 'Boo';
    }
}

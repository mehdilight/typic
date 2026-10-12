<?php

declare(strict_types=1);

namespace Typic\Tests\ValidationFixtures;

use Attribute;
use Typic\Validation\Rule;

/**
 * A custom rule: any attribute implementing Rule works.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class NotReserved implements Rule
{
    public function check(mixed $value): ?string
    {
        return in_array(strtolower((string) $value), ['admin', 'root'], true) ? 'is reserved' : null;
    }
}

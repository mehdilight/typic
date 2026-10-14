<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * A UUID such as 123e4567-e89b-12d3-a456-426614174000 (any version).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Uuid implements Rule
{
    public function __construct(private ?string $message = null)
    {
    }

    public function check(mixed $value): ?Violation
    {
        return is_string($value) && preg_match('/^[\\da-f]{8}-[\\da-f]{4}-[\\da-f]{4}-[\\da-f]{4}-[\\da-f]{12}$/iD', $value) === 1 ? null : new Violation('INVALID_UUID', $this->message ?? 'must be a valid UUID');
    }
}

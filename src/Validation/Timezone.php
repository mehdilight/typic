<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * A time zone identifier such as Africa/Casablanca or UTC.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Timezone implements Rule
{
    public function __construct(private ?string $message = null)
    {
    }

    public function check(mixed $value): ?Violation
    {
        return is_string($value) && in_array($value, \DateTimeZone::listIdentifiers(), true) ? null : new Violation('INVALID_TIMEZONE', $this->message ?? 'must be a valid time zone');
    }
}

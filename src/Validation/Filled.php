<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * When the field is sent, it must have a value: not null, a blank string or an empty list. Leaving the field
 * out is fine, which suits partial updates where omitted means "unchanged" (Laravel's "filled").
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Filled implements Rule, ImplicitRule
{
    public function __construct(private ?string $message = null)
    {
    }

    public function check(mixed $value): ?Violation
    {
        return Value::isEmpty($value) ? new Violation('BLANK', $this->message ?? 'must not be empty') : null;
    }
}

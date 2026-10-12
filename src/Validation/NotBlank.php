<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * Present, and not an empty or whitespace-only string or an empty list. Runs on null too,
 * so a nullable field with NotBlank becomes required.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class NotBlank implements Rule, ImplicitRule
{
    public function __construct(private ?string $message = null)
    {
    }

    public function check(mixed $value): ?string
    {
        return Value::isEmpty($value) ? ($this->message ?? 'must not be blank') : null;
    }
}

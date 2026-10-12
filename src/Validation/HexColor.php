<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * A CSS hex color: #rgb, #rgba, #rrggbb or #rrggbbaa.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class HexColor implements Rule
{
    public function __construct(private ?string $message = null)
    {
    }

    public function check(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#(?:(?:[0-9a-f]{3}){1,2}|(?:[0-9a-f]{4}){1,2})$/i', $value) === 1 ? null : ($this->message ?? 'must be a hex color');
    }
}

<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * A string holding valid JSON.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Json implements Rule
{
    public function __construct(private ?string $message = null)
    {
    }

    public function check(mixed $value): ?string
    {
        return is_string($value) && self::isJson($value) ? null : ($this->message ?? 'must be valid JSON');
    }

    private static function isJson(string $value): bool
    {
        if (function_exists('json_validate')) {
            return json_validate($value);
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }
}

<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * A list without duplicates.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Distinct implements Rule
{
    public function __construct(
        private bool $ignoreCase = false,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value): ?Violation
    {
        if (!is_array($value)) {
            return null;
        }

        $seen = [];
        foreach ($value as $item) {
            $key = is_string($item) && $this->ignoreCase ? mb_strtolower($item) : serialize($item);
            if (isset($seen[$key])) {
                return new Violation('DUPLICATE', $this->message ?? 'must not contain duplicates');
            }
            $seen[$key] = true;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * A number within bounds (inclusive).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Range implements Rule
{
    public function __construct(
        private int|float|null $min = null,
        private int|float|null $max = null,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        if (($this->min === null || $value >= $this->min) && ($this->max === null || $value <= $this->max)) {
            return null;
        }

        return $this->message ?? match (true) {
            $this->min !== null && $this->max !== null => "must be between {$this->min} and {$this->max}",
            $this->min !== null => "must be at least {$this->min}",
            default => "must be at most {$this->max}",
        };
    }
}

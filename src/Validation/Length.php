<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * Number of characters of a string, or of items in a list.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Length implements Rule
{
    public function __construct(
        private ?int $min = null,
        private ?int $max = null,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value): ?Violation
    {
        if (is_array($value)) {
            [$length, $unit] = [count($value), 'items'];
        } elseif (is_string($value)) {
            [$length, $unit] = [mb_strlen($value), 'characters'];
        } else {
            return null;
        }

        if (($this->min === null || $length >= $this->min) && ($this->max === null || $length <= $this->max)) {
            return null;
        }

        $verb = $unit === 'items' ? 'have' : 'be';

        $message = $this->message ?? match (true) {
            $this->min !== null && $this->max !== null => "must {$verb} between {$this->min} and {$this->max} {$unit}",
            $this->min !== null => "must {$verb} at least {$this->min} {$unit}",
            default => "must {$verb} at most {$this->max} {$unit}",
        };
        $code = $this->min !== null && $length < $this->min ? 'TOO_SHORT' : 'TOO_LONG';

        return new Violation($code, $message, ['min' => $this->min, 'max' => $this->max, 'unit' => $unit]);
    }
}

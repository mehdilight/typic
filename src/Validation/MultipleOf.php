<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;
use InvalidArgumentException;

/**
 * A number that is a multiple of $step, e.g. #[MultipleOf(0.25)] for quarter hours.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class MultipleOf implements Rule
{
    public function __construct(
        private int|float $step,
        private ?string $message = null,
    ) {
        if ($step == 0) {
            throw new InvalidArgumentException('MultipleOf needs a step other than 0.');
        }
    }

    public function check(mixed $value): ?Violation
    {
        if (!is_int($value) && !is_float($value)) {
            return $this->violation();
        }

        if (is_int($value) && is_int($this->step)) {
            $valid = $value % $this->step === 0;
        } else {
            // Compare in decimal steps, so 0.3 is a multiple of 0.1 despite float rounding
            $ratio = $value / $this->step;
            $valid = abs($ratio - round($ratio)) < 1e-9;
        }

        return $valid ? null : $this->violation();
    }

    private function violation(): Violation
    {
        return new Violation('NOT_MULTIPLE_OF', $this->message ?? "must be a multiple of {$this->step}", ['step' => $this->step]);
    }
}

<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;

/**
 * A DateTime after another one. $than is another field's name, or a date PHP understands ('now', '2026-01-01', '+1 day').
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class After implements DependentRule
{
    public function __construct(
        private string $than,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value, array $input): ?string
    {
        if (!$value instanceof DateTimeInterface) {
            return null;
        }

        $other = array_key_exists($this->than, $input) ? $input[$this->than] : $this->than;
        if (is_string($other)) {
            try {
                $other = new DateTimeImmutable($other);
            } catch (Exception) {
                return null;
            }
        }
        if (!$other instanceof DateTimeInterface || $value > $other) {
            return null;
        }

        return $this->message ?? "must be after {$this->than}";
    }
}

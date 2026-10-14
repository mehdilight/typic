<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * Equal to another field, e.g. #[Same('password')] on passwordConfirmation.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Same implements DependentRule
{
    public function __construct(
        private string $field,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value, array $input): ?Violation
    {
        return $value === ($input[$this->field] ?? null) ? null : new Violation('SAME', $this->message ?? "must match {$this->field}", ['field' => $this->field]);
    }
}

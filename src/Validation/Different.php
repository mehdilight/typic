<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Different implements DependentRule
{
    public function __construct(
        private string $field,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value, array $input): ?Violation
    {
        return $value !== ($input[$this->field] ?? null) ? null : new Violation('DIFFERENT', $this->message ?? "must be different from {$this->field}", ['field' => $this->field]);
    }
}

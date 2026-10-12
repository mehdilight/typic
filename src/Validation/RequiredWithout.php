<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * Required when any of the other fields is missing.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class RequiredWithout implements DependentRule, ImplicitRule
{
    /** @var list<string> */
    private array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = array_values($fields);
    }

    public function check(mixed $value, array $input): ?string
    {
        $present = array_filter($this->fields, static fn (string $field): bool => !Value::isEmpty($input[$field] ?? null));
        if (count($present) === count($this->fields) || !Value::isEmpty($value)) {
            return null;
        }

        return sprintf('is required when %s is missing', implode(' or ', $this->fields));
    }
}

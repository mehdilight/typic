<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * Required when any of the other fields is given.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class RequiredWith implements DependentRule, ImplicitRule
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
        if ($present === [] || !Value::isEmpty($value)) {
            return null;
        }

        return sprintf('is required when %s is given', implode(' or ', $this->fields));
    }
}

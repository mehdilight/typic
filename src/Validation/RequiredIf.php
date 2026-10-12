<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * Required when another field has one of the given values: #[RequiredIf('contact', 'EMAIL')].
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class RequiredIf implements DependentRule, ImplicitRule
{
    /** @var list<mixed> */
    private array $values;

    /**
     * @param mixed $values One value or a list of values of $field
     */
    public function __construct(
        private string $field,
        mixed $values,
        private ?string $message = null,
    ) {
        $this->values = is_array($values) ? array_values($values) : [$values];
    }

    public function check(mixed $value, array $input): ?string
    {
        $matches = in_array($input[$this->field] ?? null, $this->values, true);
        if (!$matches || !Value::isEmpty($value)) {
            return null;
        }

        return $this->message ?? sprintf('is required when %s is %s', $this->field, Value::describeList($this->values));
    }
}

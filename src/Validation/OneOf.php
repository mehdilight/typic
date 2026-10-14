<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * One of a fixed list of values; for a list, every item must be. Prefer a GraphQL enum
 * when the choices are part of your API.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class OneOf implements Rule
{
    /**
     * @param list<string|int|float|bool> $values
     */
    public function __construct(
        private array $values,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value): ?Violation
    {
        $items = is_array($value) ? $value : [$value];
        foreach ($items as $item) {
            if (!is_scalar($item) || !in_array($item, $this->values, true)) {
                return new Violation('INVALID_CHOICE', $this->message ?? 'must be one of: ' . Value::describeList($this->values), ['values' => $this->values]);
            }
        }

        return null;
    }
}

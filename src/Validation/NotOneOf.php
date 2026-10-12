<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class NotOneOf implements Rule
{
    /**
     * @param list<string|int|float|bool> $values
     */
    public function __construct(
        private array $values,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value): ?string
    {
        $items = is_array($value) ? $value : [$value];
        foreach ($items as $item) {
            if (in_array($item, $this->values, true)) {
                return $this->message ?? 'must not be one of: ' . Value::describeList($this->values);
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class EndsWith implements Rule
{
    /** @var list<string> */
    private array $affixes;

    public function __construct(string ...$affixes)
    {
        $this->affixes = array_values($affixes);
    }

    public function check(mixed $value): ?string
    {
        foreach ($this->affixes as $affix) {
            if (is_string($value) && str_ends_with($value, $affix)) {
                return null;
            }
        }

        return 'must end with one of: ' . Value::describeList($this->affixes);
    }
}

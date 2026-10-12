<?php

declare(strict_types=1);

namespace Typic\Validation;

/**
 * What to check for one argument or input field.
 *
 * @internal
 */
final class FieldPlan
{
    /**
     * @param list<Rule|DependentRule> $rules
     * @param list<Rule|DependentRule> $each Rules for every item of a list
     * @param class-string|null $input The input type to check inside the value
     */
    public function __construct(
        public readonly string $name,
        public readonly array $rules,
        public readonly array $each,
        public readonly ?string $input,
        public readonly bool $list,
    ) {
    }
}

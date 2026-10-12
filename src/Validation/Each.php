<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * Applies rules to every item of a list: #[Each(new Length(max: 20), new Pattern('/^[a-z]+$/'))].
 * Problems are reported per item, e.g. "input.tags.2".
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Each
{
    /** @var list<Rule|DependentRule> */
    public array $rules;

    public function __construct(Rule|DependentRule ...$rules)
    {
        $this->rules = array_values($rules);
    }
}

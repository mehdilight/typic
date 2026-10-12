<?php

declare(strict_types=1);

namespace Typic\Validation;

/**
 * A validation rule, used as an attribute on input fields and resolver arguments.
 *
 * Write your own by implementing this on an attribute class. Rules are skipped when the
 * value is missing or null (whether a value is required comes from the GraphQL type);
 * implement ImplicitRule as well to run anyway. To compare with other fields, implement
 * DependentRule instead.
 */
interface Rule
{
    /**
     * @return string|null What is wrong, e.g. "must be at most 20 characters", or null when valid
     */
    public function check(mixed $value): ?string;
}

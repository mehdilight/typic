<?php

declare(strict_types=1);

namespace Typic\Validation;

/**
 * A validation rule, used as an attribute on input fields and resolver arguments.
 *
 * Write your own by implementing this on an attribute class. Rules are skipped when the
 * value is missing, null or a blank string (whether a value is required comes from the GraphQL
 * type and NotBlank); implement ImplicitRule as well to run anyway. To compare with other fields, implement
 * DependentRule instead.
 */
interface Rule
{
    /**
     * @return Violation|null What is wrong, e.g. new Violation('TOO_LONG', 'must be at most 20 characters', ['max' => 20]),
     *         or null when valid
     */
    public function check(mixed $value): ?Violation;
}

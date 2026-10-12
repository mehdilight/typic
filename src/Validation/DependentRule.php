<?php

declare(strict_types=1);

namespace Typic\Validation;

/**
 * A rule that looks at other fields of the same input object (or the other arguments, for
 * a resolver argument), e.g. "must match password" or "is required when type is EMAIL".
 */
interface DependentRule
{
    /**
     * @param array<string, mixed> $input The sibling fields, by GraphQL name
     * @return string|null What is wrong, or null when valid
     */
    public function check(mixed $value, array $input): ?string;
}

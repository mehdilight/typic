<?php

declare(strict_types=1);

namespace Typic\Validation;

/**
 * @internal Helpers shared by the rules.
 */
final class Value
{
    /**
     * Missing, null, an empty or whitespace-only string, or an empty list.
     */
    public static function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    /**
     * @param array<mixed> $values
     */
    public static function describeList(array $values): string
    {
        return implode(', ', array_map(static fn (mixed $v): string => match (true) {
            is_bool($v) => $v ? 'true' : 'false',
            $v === null => 'null',
            is_scalar($v) => (string) $v,
            default => get_debug_type($v),
        }, $values));
    }
}

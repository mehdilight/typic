<?php

declare(strict_types=1);

namespace Typic\Scalar;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * DateTime: an ISO 8601 date and time with a time zone, e.g. 2026-10-12T10:00:00+00:00.
 *
 * Used for every DateTimeInterface type. Resolvers receive DateTimeImmutable (or DateTime
 * when the parameter asks for it).
 */
final class DateTimeScalar implements ScalarType
{
    private const PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})$/';

    public function name(): string
    {
        return 'DateTime';
    }

    public function description(): string
    {
        return 'An ISO 8601 date and time with a time zone, e.g. 2026-10-12T10:00:00+00:00.';
    }

    public function serialize(mixed $value): string
    {
        if (!$value instanceof DateTimeInterface) {
            throw new InvalidArgumentException('DateTime fields must return a DateTimeInterface, got ' . get_debug_type($value) . '.');
        }

        return $value->format(DateTimeInterface::ATOM);
    }

    public function parse(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('DateTime must be an ISO 8601 date and time with a time zone, like 2026-10-12T10:00:00+00:00.');
        }

        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('DateTime must contain a valid calendar date and time.');
        }
        return $date;
    }
}

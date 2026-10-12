<?php

declare(strict_types=1);

namespace Typic\Tests\ScalarFixtures;

use InvalidArgumentException;
use Typic\Scalar\ScalarType;

final class UuidScalar implements ScalarType
{
    public function name(): string
    {
        return 'UUID';
    }

    public function description(): string
    {
        return 'A UUID such as 123e4567-e89b-12d3-a456-426614174000';
    }

    public function serialize(mixed $value): string
    {
        return $value instanceof Uuid ? $value->value : throw new InvalidArgumentException('Expected a Uuid.');
    }

    public function parse(mixed $value): Uuid
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new InvalidArgumentException('Not a valid UUID.');
        }

        return new Uuid(strtolower($value));
    }
}

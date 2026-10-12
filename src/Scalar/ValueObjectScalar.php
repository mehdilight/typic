<?php

declare(strict_types=1);

namespace Typic\Scalar;

use InvalidArgumentException;

/**
 * Adapts a #[Scalar] value object class to ScalarType.
 *
 * @internal
 */
final class ValueObjectScalar implements ScalarType
{
    /**
     * @param class-string<ScalarValue> $class
     */
    public function __construct(
        private string $class,
        private string $name,
        private ?string $description,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function serialize(mixed $value): mixed
    {
        if (!$value instanceof $this->class) {
            throw new InvalidArgumentException("{$this->name} fields must return {$this->class}, got " . get_debug_type($value) . '.');
        }

        return $value->serialize();
    }

    public function parse(mixed $value): ScalarValue
    {
        return $this->class::parse($value);
    }
}

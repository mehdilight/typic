<?php

declare(strict_types=1);

namespace Typic\Tests\ScalarFixtures;

use InvalidArgumentException;
use Typic\Attribute\Scalar;
use Typic\Scalar\ScalarValue;

#[Scalar(description: 'An email address')]
final class Email implements ScalarValue
{
    private function __construct(public readonly string $address)
    {
    }

    public static function parse(mixed $value): static
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Not a valid email address: ' . (is_string($value) ? $value : get_debug_type($value)));
        }

        return new self(strtolower($value));
    }

    public function serialize(): string
    {
        return $this->address;
    }
}

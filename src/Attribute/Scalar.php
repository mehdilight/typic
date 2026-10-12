<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

/**
 * Makes a value object class implementing Typic\Scalar\ScalarValue a GraphQL scalar.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Scalar
{
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
class Field
{
    /**
     * @param class-string|string|null $arrayOf Class name or scalar type if field is a list
     */
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?string $description = null,
        public ?string $deprecationReason = null,
        public ?string $arrayOf = null,
        public ?bool $nullable = null,
    ) {
    }
}

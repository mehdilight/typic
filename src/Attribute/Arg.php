<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Arg
{
    /**
     * @param class-string|string|null $arrayOf
     */
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?string $description = null,
        public mixed $defaultValue = null,
        public ?string $arrayOf = null,
        public ?bool $nullable = null,
    ) {
    }
}

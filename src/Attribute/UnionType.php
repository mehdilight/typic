<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class UnionType
{
    /**
     * @param array<class-string> $types
     */
    public function __construct(
        public array $types,
        public ?string $name = null,
        public ?string $description = null,
    ) {
    }
}

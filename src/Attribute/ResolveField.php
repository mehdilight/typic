<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ResolveField
{
    /**
     * @param class-string $target The target ObjectType class this field belongs to
     * @param class-string|string|null $arrayOf
     */
    public function __construct(
        public string $target,
        public ?string $name = null,
        public ?string $type = null,
        public ?string $description = null,
        public ?string $deprecationReason = null,
        public ?string $arrayOf = null,
        public ?bool $nullable = null,
    ) {
    }
}

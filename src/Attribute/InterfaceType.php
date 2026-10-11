<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class InterfaceType
{
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
    ) {
    }
}

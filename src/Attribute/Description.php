<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Description
{
    public function __construct(
        public string $text,
    ) {
    }
}

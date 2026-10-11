<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY)]
class Deprecated
{
    public function __construct(
        public string $reason = 'No longer supported',
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class GroupBy
{
    /**
     * @param string|callable $key
     */
    public function __construct(
        public mixed $key,
    ) {
    }
}

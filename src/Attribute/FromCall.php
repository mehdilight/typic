<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

/**
 * Injects a value that middleware passed along with $call->with($key, $value).
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class FromCall
{
    public function __construct(
        public string $key,
    ) {
    }
}

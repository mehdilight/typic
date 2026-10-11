<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

/**
 * Injects a dependency from the PSR-11 container into a resolver method parameter.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Inject
{
    public function __construct(
        public ?string $id = null,
    ) {
    }
}

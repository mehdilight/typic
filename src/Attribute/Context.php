<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

/**
 * Injects the GraphQL request Context into a resolver method parameter.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Context
{
}

<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

/**
 * Injects the GraphQL ResolveInfo object into a resolver method parameter.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Info
{
}

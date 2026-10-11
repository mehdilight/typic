<?php

declare(strict_types=1);

namespace Typic\Attribute;

use Attribute;

/**
 * Marks a property, method return, or parameter as the GraphQL ID scalar.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
class ID
{
}

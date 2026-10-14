<?php

declare(strict_types=1);

namespace Typic\Tests\ListFixtures;

use Typic\Attribute\EnumType;

#[EnumType]
enum Color: string
{
    case RED = 'RED';
    case BLUE = 'BLUE';
}

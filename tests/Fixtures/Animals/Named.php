<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Animals;

use Typic\Attribute\Field;
use Typic\Attribute\InterfaceType;

#[InterfaceType(description: 'Anything with a name')]
interface Named
{
    #[Field(description: 'Display name')]
    public function name(): string;
}

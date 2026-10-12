<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Animals;

use Typic\Attribute\Field;
use Typic\Attribute\InterfaceType;

#[InterfaceType]
abstract class Pet implements Named
{
    public function __construct(
        #[Field] public string $owner,
    ) {
    }
}

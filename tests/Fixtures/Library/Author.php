<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Library;

use Typic\Attribute\Field;
use Typic\Attribute\ID;
use Typic\Attribute\ObjectType;

#[ObjectType]
class Author
{
    public function __construct(
        #[Field] #[ID] public int $id,
        #[Field] public string $name,
    ) {
    }
}

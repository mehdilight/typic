<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Live;

use Typic\Attribute\Field;
use Typic\Attribute\ID;
use Typic\Attribute\ObjectType;

#[ObjectType(name: 'LiveMessage')]
class LiveMessage
{
    public function __construct(
        #[Field] #[ID] public int $id,
        #[Field] public string $text,
    ) {
    }
}

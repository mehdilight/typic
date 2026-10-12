<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\ObjectType;

#[ObjectType]
class Secret
{
    public function __construct(
        public int $id,
        public string $owner,
        public string $text,
    ) {
    }
}

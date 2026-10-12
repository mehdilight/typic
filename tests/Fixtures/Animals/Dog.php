<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Animals;

use Typic\Attribute\Field;
use Typic\Attribute\ObjectType;

#[ObjectType]
final class Dog extends Pet
{
    public function __construct(
        string $owner,
        private string $dogName,
        #[Field] public string $breed,
    ) {
        parent::__construct($owner);
    }

    // No attribute here: the field comes from Named
    public function name(): string
    {
        return $this->dogName;
    }
}

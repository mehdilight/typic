<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures;

use Typic\Attribute\Field;
use Typic\Attribute\ID;
use Typic\Attribute\ObjectType;

#[ObjectType(name: 'User', description: 'A user account')]
class User
{
    #[Field]
    #[ID]
    public int $id;

    #[Field]
    public string $name;

    #[Field]
    public string $email;

    public function __construct(int $id, string $name, string $email)
    {
        $this->id = $id;
        $this->name = $name;
        $this->email = $email;
    }
}

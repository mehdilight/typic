<?php

declare(strict_types=1);

namespace Typic\Tests\HttpFixtures;

use GraphQL\Error\UserError;
use RuntimeException;
use Typic\Attribute\Query;
use Typic\Validation\Length;

class BrokenResolver
{
    #[Query]
    public function crash(): string
    {
        throw new RuntimeException('Database is on fire');
    }

    #[Query]
    public function refuse(): string
    {
        throw new UserError('Not allowed');
    }

    #[Query]
    public function checked(#[Length(min: 3)] string $name): string
    {
        return $name;
    }
}

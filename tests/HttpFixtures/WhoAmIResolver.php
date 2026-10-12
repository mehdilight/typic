<?php

declare(strict_types=1);

namespace Typic\Tests\HttpFixtures;

use Typic\Attribute\FromRequest;
use Typic\Attribute\Query;

class WhoAmIResolver
{
    #[Query]
    public function whoami(#[FromRequest('user')] ?string $user = null): string
    {
        return $user ?? 'anonymous';
    }

    #[Query]
    public function mustBeUser(#[FromRequest('user')] string $user): string
    {
        return $user;
    }
}

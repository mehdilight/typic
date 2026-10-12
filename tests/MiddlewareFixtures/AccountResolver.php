<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\FromCall;
use Typic\Attribute\Middleware;
use Typic\Attribute\Query;
use Typic\Attribute\WithoutMiddleware;

#[Middleware(Authenticated::class)]
class AccountResolver
{
    #[Query]
    public function whoami(#[FromCall('user')] string $user): string
    {
        return $user;
    }

    #[Query]
    #[Middleware('admin')]
    public function adminPanel(): string
    {
        Trace::$log[] = 'resolver';

        return 'secrets';
    }

    #[Query]
    #[WithoutMiddleware(Authenticated::class)]
    public function greeting(#[FromCall('user')] ?string $user = null): string
    {
        return 'hello ' . ($user ?? 'guest');
    }

    #[Query]
    #[WithoutMiddleware(Authenticated::class)]
    public function needsUser(#[FromCall('user')] string $user): string
    {
        return $user;
    }
}

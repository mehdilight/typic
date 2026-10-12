<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\Middleware;
use Typic\Attribute\Query;

class NeedsArgsResolver
{
    #[Query]
    #[Middleware(Can::class)]
    public function guarded(): string
    {
        return 'never without a user';
    }
}

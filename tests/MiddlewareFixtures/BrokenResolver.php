<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\Middleware;
use Typic\Attribute\Query;

class BrokenResolver
{
    #[Query]
    #[Middleware('missing')]
    public function broken(): string
    {
        return 'never';
    }
}

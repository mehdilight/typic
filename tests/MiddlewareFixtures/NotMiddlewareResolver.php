<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\Middleware;
use Typic\Attribute\Query;

class NotMiddlewareResolver
{
    #[Query]
    #[Middleware(\stdClass::class)]
    public function wrong(): string
    {
        return 'never';
    }
}

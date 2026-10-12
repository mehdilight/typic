<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\Query;
use Typic\Attribute\WithoutMiddleware;

class UnknownWithoutResolver
{
    #[Query]
    #[WithoutMiddleware('nope')]
    public function wrong(): string
    {
        return 'never';
    }
}

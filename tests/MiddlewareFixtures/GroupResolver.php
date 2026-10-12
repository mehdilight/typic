<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\Middleware;
use Typic\Attribute\Query;
use Typic\Attribute\WithoutMiddleware;

class GroupResolver
{
    #[Query]
    #[Middleware('outer')]
    public function nested(): string
    {
        Trace::$log[] = 'resolver';

        return 'ok';
    }

    #[Query]
    #[WithoutMiddleware('inner')]
    public function withoutGroup(): string
    {
        Trace::$log[] = 'resolver';

        return 'ok';
    }
}

<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\Middleware;
use Typic\Attribute\Query;
use Typic\Attribute\WithoutMiddleware;

#[Middleware(new Record('class 1'), new Record('class 2'))]
class StackedResolver
{
    #[Query]
    #[Middleware(new Record('method 1'))]
    #[Middleware(new Record('method 2'))]
    public function stacked(): string
    {
        return self::resolve();
    }

    #[Query]
    public function classOnly(): string
    {
        return self::resolve();
    }

    #[Query]
    #[Middleware('traced', new Record('traced'))]
    public function twice(): string
    {
        return self::resolve();
    }

    #[Query]
    #[WithoutMiddleware(Record::class)]
    #[Middleware(new Record('own'))]
    public function withoutInheritedRecords(): string
    {
        return self::resolve();
    }

    private static function resolve(): string
    {
        Trace::$log[] = 'resolver';

        return 'ok';
    }
}

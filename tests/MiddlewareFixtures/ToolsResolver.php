<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\Middleware;
use Typic\Attribute\Query;

class ToolsResolver
{
    #[Query]
    #[Middleware(new TrimArgs(), new Uppercase())]
    public function shout(string $text): string
    {
        return $text . '!';
    }

    #[Query]
    #[Middleware(new ReturnEarly('from middleware'))]
    public function early(): string
    {
        Trace::$log[] = 'resolver';

        return 'from resolver';
    }

    #[Query]
    #[Middleware(new Inspect())]
    public function inspected(int $limit): int
    {
        return $limit;
    }

    #[Query]
    public function badge(): Badge
    {
        return new Badge();
    }
}

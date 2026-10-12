<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\Field;
use Typic\Attribute\Middleware;
use Typic\Attribute\ObjectType;

#[ObjectType]
#[Middleware(new Record('type'))]
class Badge
{
    #[Field]
    public string $label = 'gold';

    #[Field]
    public function shine(): string
    {
        Trace::$log[] = 'resolver';

        return 'shiny';
    }
}

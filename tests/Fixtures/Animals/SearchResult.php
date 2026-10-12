<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Animals;

use Typic\Attribute\UnionType;

#[UnionType(types: [Dog::class, Robot::class], description: 'Something search can find')]
interface SearchResult
{
}

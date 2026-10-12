<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Library;

use RuntimeException;
use Typic\DataLoader\DataLoader;

/**
 * @extends DataLoader<int, Author|null>
 */
class FailingAuthorLoader extends DataLoader
{
    protected function batch(array $ids): iterable
    {
        throw new RuntimeException('Author service is down');
    }
}

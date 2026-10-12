<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Library;

use Typic\DataLoader\DataLoader;

/**
 * @extends DataLoader<int, Author|null>
 */
class AuthorLoader extends DataLoader
{
    protected function batch(array $ids): iterable
    {
        return $this->keyBy(Library::authorsWhereIdIn($ids), 'id');
    }
}

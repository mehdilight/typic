<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Library;

use Typic\DataLoader\DataLoader;

/**
 * Forgets to key its results: the mistake DataLoader should explain.
 *
 * @extends DataLoader<int, Author|null>
 */
class UnkeyedAuthorLoader extends DataLoader
{
    protected function batch(array $ids): iterable
    {
        return Library::authorsWhereIdIn($ids);
    }
}

<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Library;

use Typic\DataLoader\DataLoader;

/**
 * @extends DataLoader<int, list<Book>>
 */
class BooksByAuthorLoader extends DataLoader
{
    protected function batch(array $authorIds): iterable
    {
        return $this->groupBy(Library::booksWhereAuthorIdIn($authorIds), 'authorId');
    }
}

<?php

declare(strict_types=1);

namespace Typic\Tests\MiddlewareFixtures;

use Typic\Attribute\Middleware;
use Typic\Attribute\ResolveField;
use Typic\Tests\Fixtures\Library\AuthorLoader;
use Typic\Tests\Fixtures\Library\Book;

class ShoutingLibraryResolver
{
    #[ResolveField(target: Book::class)]
    #[Middleware(new Uppercase(), new Inspect())]
    public function shoutedAuthor(Book $book, AuthorLoader $authors): string
    {
        return $authors->get($book->authorId)->name ?? 'unknown';
    }
}

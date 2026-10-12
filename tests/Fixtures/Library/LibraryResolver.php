<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Library;

use GraphQL\Deferred;
use Typic\Attribute\Query;
use Typic\Attribute\ResolveField;

class LibraryResolver
{
    /**
     * @return list<Book>
     */
    #[Query(arrayOf: Book::class)]
    public function books(): array
    {
        return Library::books();
    }

    /**
     * @return list<Author>
     */
    #[Query(arrayOf: Author::class)]
    public function authors(): array
    {
        return Library::authors();
    }

    #[ResolveField(target: Book::class)]
    public function author(Book $book, AuthorLoader $authors): ?Author
    {
        return $authors->get($book->authorId);
    }

    /**
     * @return list<Book>
     */
    #[ResolveField(target: Author::class, name: 'books', arrayOf: Book::class)]
    public function authorBooks(Author $author, BooksByAuthorLoader $books): array
    {
        return $books->get($author->id);
    }

    /**
     * Two loads in one resolver: the second waits for the first.
     */
    #[ResolveField(target: Book::class)]
    public function authorName(Book $book, AuthorLoader $authors): string
    {
        $author = $authors->get($book->authorId);

        return $author->name ?? 'unknown';
    }

    /**
     * @return list<Author>
     */
    #[ResolveField(target: Book::class, arrayOf: Author::class)]
    public function coAuthors(Book $book, AuthorLoader $authors): array
    {
        // Unknown ids come back as null
        return array_values(array_filter($authors->getMany([$book->authorId, 3])));
    }

    #[ResolveField(target: Book::class, type: Author::class)]
    public function authorDeferred(Book $book, AuthorLoader $authors): Deferred
    {
        return $authors->load($book->authorId);
    }

    #[ResolveField(target: Book::class)]
    public function unkeyedAuthor(Book $book, UnkeyedAuthorLoader $authors): ?Author
    {
        return $authors->get($book->authorId);
    }

    #[ResolveField(target: Book::class)]
    public function failingAuthor(Book $book, FailingAuthorLoader $authors): ?Author
    {
        return $authors->get($book->authorId);
    }
}

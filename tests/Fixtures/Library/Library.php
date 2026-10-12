<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Library;

/**
 * In-memory "database" that records every query, so tests can count them.
 */
final class Library
{
    /** @var list<string> */
    public static array $queries = [];

    /**
     * @param list<int> $ids
     * @return list<Author>
     */
    public static function authorsWhereIdIn(array $ids): array
    {
        self::$queries[] = 'authors ' . implode(',', $ids);

        return array_values(array_filter(self::authors(), fn (Author $a): bool => in_array($a->id, $ids, true)));
    }

    /**
     * @param list<int> $authorIds
     * @return list<Book>
     */
    public static function booksWhereAuthorIdIn(array $authorIds): array
    {
        self::$queries[] = 'books ' . implode(',', $authorIds);

        return array_values(array_filter(self::books(), fn (Book $b): bool => in_array($b->authorId, $authorIds, true)));
    }

    /**
     * @return list<Author>
     */
    public static function authors(): array
    {
        return [new Author(1, 'Ursula'), new Author(2, 'Terry'), new Author(3, 'Nobody')];
    }

    /**
     * @return list<Book>
     */
    public static function books(): array
    {
        return [new Book(1, 'Earthsea', 1), new Book(2, 'Mort', 2), new Book(3, 'The Dispossessed', 1), new Book(4, 'Orphan', 99)];
    }
}

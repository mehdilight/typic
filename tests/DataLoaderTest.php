<?php

declare(strict_types=1);

namespace Typic\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use Typic\Server\TypicServer;
use Typic\Tests\Fixtures\Library\Author;
use Typic\Tests\Fixtures\Library\AuthorLoader;
use Typic\Tests\Fixtures\Library\Book;
use Typic\Tests\Fixtures\Library\Library;
use Typic\Tests\Fixtures\Library\LibraryResolver;
use Typic\Typic;

class DataLoaderTest extends TestCase
{
    private TypicServer $server;

    protected function setUp(): void
    {
        Library::$queries = [];
        $this->server = Typic::builder()
            ->registerResolvers([LibraryResolver::class])
            ->registerTypes([Author::class, Book::class])
            ->build();
    }

    public function test_get_batches_every_sibling_into_one_query(): void
    {
        $result = $this->server->execute('{ books { title author { name } } }');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame(
            [['Earthsea', 'Ursula'], ['Mort', 'Terry'], ['The Dispossessed', 'Ursula'], ['Orphan', null]],
            array_map(fn (array $b): array => [$b['title'], $b['author']['name'] ?? null], $result['data']['books']),
        );
        // Four books, three distinct author ids, one query
        $this->assertSame(['authors 1,2,99'], Library::$queries);
    }

    public function test_group_by_gives_every_key_a_list(): void
    {
        $result = $this->server->execute('{ authors { name books { title } } }');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame([
            ['name' => 'Ursula', 'books' => [['title' => 'Earthsea'], ['title' => 'The Dispossessed']]],
            ['name' => 'Terry', 'books' => [['title' => 'Mort']]],
            ['name' => 'Nobody', 'books' => []],
        ], $result['data']['authors']);
        $this->assertSame(['books 1,2,3'], Library::$queries);
    }

    public function test_nested_levels_batch_per_level_and_reuse_the_cache(): void
    {
        $result = $this->server->execute('{ authors { books { title author { name } authorName } } }');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame('Ursula', $result['data']['authors'][0]['books'][1]['author']['name']);
        $this->assertSame('Ursula', $result['data']['authors'][0]['books'][1]['authorName']);
        // author and authorName share the same request-scoped AuthorLoader
        $this->assertSame(['books 1,2,3', 'authors 1,2'], Library::$queries);
    }

    public function test_get_many_and_promise_style_load_join_the_same_batch(): void
    {
        $result = $this->server->execute('{ books { coAuthors { name } authorDeferred { name } } }');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame([['name' => 'Ursula'], ['name' => 'Nobody']], $result['data']['books'][0]['coAuthors']);
        $this->assertSame('Terry', $result['data']['books'][1]['authorDeferred']['name']);
        $this->assertSame(['authors 1,3,2,99'], Library::$queries);
    }

    public function test_unkeyed_results_explain_the_fix(): void
    {
        $result = $this->debugServer()->execute('{ books { unkeyedAuthor { name } } }');

        $this->assertStringContainsString('must be keyed by the requested keys', $result['errors'][0]['extensions']['debugMessage'] ?? '');
    }

    public function test_batch_errors_reach_every_waiting_field(): void
    {
        $result = $this->debugServer()->execute('{ books { failingAuthor { name } } }');

        $this->assertCount(4, $result['errors']);
        $this->assertSame('Author service is down', $result['errors'][0]['extensions']['debugMessage'] ?? null);
    }

    public function test_get_outside_a_resolver_points_to_load(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Elsewhere, return');

        (new AuthorLoader())->get(1);
    }

    public function test_loader_factory_creates_one_instance_per_execution_and_preserves_batching(): void
    {
        $created = [];
        $server = Typic::builder()->registerResolvers([LibraryResolver::class])
            ->withDataLoaderFactory(static function (string $class) use (&$created): \Typic\DataLoader\DataLoader {
                $loader = new $class();
                $created[] = $loader;
                return $loader;
            })->build();
        $query = '{ books { author { name } } }';
        $this->assertArrayNotHasKey('errors', $server->execute($query));
        $this->assertArrayNotHasKey('errors', $server->execute($query));
        $this->assertCount(2, $created);
        $this->assertNotSame($created[0], $created[1]);
        $this->assertSame(['authors 1,2,99', 'authors 1,2,99'], Library::$queries);
    }

    private function debugServer(): TypicServer
    {
        return Typic::builder()
            ->registerResolvers([LibraryResolver::class])
            ->registerTypes([Author::class, Book::class])
            ->withDebug(true)
            ->build();
    }
}

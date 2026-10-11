<?php

declare(strict_types=1);

namespace Typic\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Typic\Tests\Fixtures\CreatePostInput;
use Typic\Tests\Fixtures\Post;
use Typic\Tests\Fixtures\PostFieldResolver;
use Typic\Tests\Fixtures\PostMetadataInput;
use Typic\Tests\Fixtures\PostResolver;
use Typic\Tests\Fixtures\StatusEnum;
use Typic\Tests\Fixtures\User;
use Typic\Tests\Fixtures\UserDataLoader;
use Typic\Typic;

class TypicTest extends TestCase
{
    public function test_can_build_server_and_query_schema(): void
    {
        $server = Typic::builder()
            ->registerResolvers([
                PostResolver::class,
                PostFieldResolver::class,
            ])
            ->registerTypes([
                Post::class,
                User::class,
                StatusEnum::class,
                CreatePostInput::class,
                PostMetadataInput::class,
            ])
            ->build();

        $result = $server->execute('{
            posts {
                id
                title
                status
                snippet
            }
        }');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertCount(3, $result['data']['posts']);
        $this->assertSame('First Post', $result['data']['posts'][0]['title']);
        $this->assertSame('PUBLISHED', $result['data']['posts'][0]['status']);
        $this->assertSame('Hello worl...', $result['data']['posts'][0]['snippet']);
    }

    public function test_enum_filtering_in_query(): void
    {
        $server = Typic::builder()
            ->registerResolvers([PostResolver::class, PostFieldResolver::class])
            ->registerTypes([Post::class, User::class, StatusEnum::class])
            ->build();

        $result = $server->execute('{
            posts(status: DRAFT) {
                id
                title
                status
            }
        }');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertCount(1, $result['data']['posts']);
        $this->assertSame('Second Post', $result['data']['posts'][0]['title']);
        $this->assertSame('DRAFT', $result['data']['posts'][0]['status']);
    }

    public function test_dataloader_batching_eliminates_n_plus_one(): void
    {
        $server = Typic::builder()
            ->registerResolvers([PostResolver::class, PostFieldResolver::class])
            ->registerTypes([Post::class, User::class, StatusEnum::class])
            ->build();

        $result = $server->execute('{
            posts {
                id
                title
                author {
                    id
                    name
                    email
                }
            }
        }');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertCount(3, $result['data']['posts']);
        $this->assertSame('Alice', $result['data']['posts'][0]['author']['name']);
        $this->assertSame('Bob', $result['data']['posts'][1]['author']['name']);
        $this->assertSame('Alice', $result['data']['posts'][2]['author']['name']);
    }

    public function test_nested_input_dto_mutation(): void
    {
        $server = Typic::builder()
            ->registerResolvers([PostResolver::class, PostFieldResolver::class])
            ->registerTypes([Post::class, User::class, StatusEnum::class, CreatePostInput::class, PostMetadataInput::class])
            ->build();

        $mutation = '
            mutation Create($input: CreatePostInput!) {
                createPost(input: $input) {
                    id
                    title
                    content
                    status
                }
            }
        ';

        $variables = [
            'input' => [
                'title' => 'New Awesome Post',
                'content' => 'Full blog article content here',
                'status' => 'PUBLISHED',
                'metadata' => [
                    'seoDescription' => 'SEO description text',
                    'tags' => ['php', 'graphql', 'typic'],
                ],
            ],
        ];

        $result = $server->execute($mutation, $variables);

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame('4', (string) $result['data']['createPost']['id']);
        $this->assertSame('New Awesome Post', $result['data']['createPost']['title']);
        $this->assertSame('PUBLISHED', $result['data']['createPost']['status']);
    }

    public function test_offset_pagination(): void
    {
        $server = Typic::builder()
            ->registerResolvers([PostResolver::class, PostFieldResolver::class])
            ->registerTypes([Post::class, User::class, StatusEnum::class])
            ->build();

        $result = $server->execute('{
            paginatedPosts(page: 1, perPage: 2) {
                data {
                    id
                    title
                }
                paginatorInfo {
                    currentPage
                    lastPage
                    perPage
                    total
                    hasMorePages
                }
            }
        }');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertCount(2, $result['data']['paginatedPosts']['data']);
        $this->assertSame(1, $result['data']['paginatedPosts']['paginatorInfo']['currentPage']);
        $this->assertSame(2, $result['data']['paginatedPosts']['paginatorInfo']['lastPage']);
        $this->assertSame(3, $result['data']['paginatedPosts']['paginatorInfo']['total']);
        $this->assertTrue($result['data']['paginatedPosts']['paginatorInfo']['hasMorePages']);
    }

    public function test_relay_connection_pagination(): void
    {
        $server = Typic::builder()
            ->registerResolvers([PostResolver::class, PostFieldResolver::class])
            ->registerTypes([Post::class, User::class, StatusEnum::class])
            ->build();

        $result = $server->execute('{
            postsConnection(first: 2) {
                edges {
                    cursor
                    node {
                        id
                        title
                    }
                }
                pageInfo {
                    hasNextPage
                    hasPreviousPage
                    startCursor
                    endCursor
                }
                totalCount
            }
        }');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertCount(2, $result['data']['postsConnection']['edges']);
        $this->assertSame(3, $result['data']['postsConnection']['totalCount']);
        $this->assertTrue($result['data']['postsConnection']['pageInfo']['hasNextPage']);
        $this->assertFalse($result['data']['postsConnection']['pageInfo']['hasPreviousPage']);
    }

    public function test_psr15_http_handling(): void
    {
        $server = Typic::builder()
            ->registerResolvers([PostResolver::class, PostFieldResolver::class])
            ->registerTypes([Post::class, User::class, StatusEnum::class])
            ->build();

        // 1. POST JSON query
        $request = new ServerRequest('POST', '/graphql', [
            'Content-Type' => 'application/json',
        ], (string) json_encode(['query' => '{ posts { id title } }']));

        $response = $server->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertCount(3, $body['data']['posts']);

        // 2. GET Apollo Sandbox HTML
        $getHtmlRequest = new ServerRequest('GET', '/graphql', [
            'Accept' => 'text/html',
        ]);
        $htmlResponse = $server->handle($getHtmlRequest);
        $this->assertSame(200, $htmlResponse->getStatusCode());
        $this->assertStringContainsString('text/html', $htmlResponse->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Typic Apollo Sandbox', (string) $htmlResponse->getBody());
        $this->assertStringContainsString('embeddable-sandbox.cdn.apollographql.com', (string) $htmlResponse->getBody());
    }

    public function test_directory_scanner_auto_discovery(): void
    {
        $server = Typic::builder()
            ->scanDirectories([__DIR__ . '/Fixtures'])
            ->build();

        $result = $server->execute('{
            posts {
                id
                title
            }
        }');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertCount(3, $result['data']['posts']);
    }
}

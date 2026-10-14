<?php

declare(strict_types=1);

namespace Typic\Tests;

use Nyholm\Psr7\ServerRequest;
use GraphQL\Type\Introspection;
use PHPUnit\Framework\TestCase;
use Typic\Server\TypicBuilder;
use Typic\Subscription\Protocol\GraphqlWsHandler;
use Typic\Tests\Fixtures\Library\Author;
use Typic\Tests\Fixtures\Library\Book;
use Typic\Tests\Fixtures\Library\LibraryResolver;
use Typic\Tests\MiddlewareFixtures\HandshakeConnection;
use Typic\Tests\MiddlewareFixtures\Secret;
use Typic\Tests\MiddlewareFixtures\SecretResolver;
use Typic\Tests\Support\NullDriver;
use Typic\Subscription\PubSub\InMemoryPubSub;
use Typic\Typic;

class ProductionLimitsTest extends TestCase
{
    public function test_explorer_introspection_works_without_relaxing_application_depth(): void
    {
        $server = $this->library()->withQueryLimits(maxDepth: 1, maxComplexity: 200)->build();

        $schema = $server->execute(Introspection::getIntrospectionQuery());
        $this->assertArrayNotHasKey('errors', $schema);
        $this->assertNotEmpty($schema['data']['__schema']['types']);

        $type = $server->execute('{ schema: __type(name: "Book") { fields { type { ofType { ofType { name } } } } } }');
        $this->assertArrayNotHasKey('errors', $type);

        $deep = $server->execute('{ schema: __schema { queryType { fields { type { name } } } } authors { books { author { name } } } }');
        $this->assertSame('Max query depth should be 1 but got 2.', $deep['errors'][0]['message']);

        // A previous schema request cannot influence the next application's depth.
        $this->assertArrayNotHasKey('errors', $server->execute('{ books { title } }'));
    }

    public function test_queries_deeper_than_the_limit_are_refused(): void
    {
        // Root fields are level 0: authors 0, books 1, author 2
        $server = $this->library()->withQueryLimits(maxDepth: 1)->build();

        $this->assertArrayNotHasKey('errors', $server->execute('{ authors { books { title } } }'));

        $deep = $server->execute('{ authors { books { author { name } } } }');
        $this->assertSame('Max query depth should be 1 but got 2.', $deep['errors'][0]['message']);
        $this->assertArrayNotHasKey('data', $deep);
    }

    public function test_queries_more_complex_than_the_limit_are_refused(): void
    {
        $server = $this->library()->withQueryLimits(maxComplexity: 3)->build();

        $this->assertArrayNotHasKey('errors', $server->execute('{ books { title } }'));
        $this->assertSame(
            'Max query complexity should be 3 but got 4.',
            $server->execute('{ books { id title authorName } }')['errors'][0]['message'],
        );
    }

    public function test_introspection_can_be_turned_off(): void
    {
        $server = $this->library()->withIntrospection(false)->build();

        $result = $server->execute('{ __schema { queryType { name } } }');
        $this->assertStringContainsString('introspection is not allowed', $result['errors'][0]['message']);

        // __typename is not introspection of the schema, clients rely on it
        $this->assertArrayNotHasKey('errors', $server->execute('{ books { __typename } }'));
    }

    public function test_requests_larger_than_the_limit_are_refused(): void
    {
        $server = $this->library()->withMaxRequestSize(64)->build();

        $small = $server->handle($this->post('{ books { title } }'));
        $this->assertSame(200, $small->getStatusCode());

        $large = $server->handle($this->post('{ books { title ' . str_repeat('id ', 30) . '} }'));
        $this->assertSame(413, $large->getStatusCode());
        $this->assertSame('The request is larger than 64 bytes.', json_decode((string) $large->getBody(), true)['errors'][0]['message']);
    }

    public function test_subscriptions_follow_the_same_limits(): void
    {
        $server = Typic::builder()
            ->registerResolvers([SecretResolver::class])
            ->registerTypes([Secret::class])
            ->withIntrospection(false)
            ->withSubscriptions(driver: new NullDriver(), pubSub: new InMemoryPubSub())
            ->build();
        $handler = $server->getSubscriptionServer()?->getProtocolHandler();
        $this->assertInstanceOf(GraphqlWsHandler::class, $handler);

        $connection = new HandshakeConnection();
        $handler->handleConnect($connection);
        $handler->handleMessage($connection, (string) json_encode(['type' => 'connection_init']));
        $handler->handleMessage($connection, (string) json_encode([
            'id' => '1',
            'type' => 'subscribe',
            'payload' => ['query' => 'subscription S { blocked { id } } query Q { __schema { description } }', 'operationName' => 'S'],
        ]));

        $this->assertSame('error', $connection->received[1]['type']);
        $messages = array_column($connection->received[1]['payload'], 'message');
        $this->assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'introspection is not allowed')));
        $this->assertSame([], $handler->getActiveSubscriptions()[$connection->getId()] ?? []);
    }

    private function library(): TypicBuilder
    {
        return Typic::builder()
            ->registerResolvers([LibraryResolver::class])
            ->registerTypes([Author::class, Book::class]);
    }

    private function post(string $query): ServerRequest
    {
        return new ServerRequest('POST', '/graphql', ['Content-Type' => 'application/json'], (string) json_encode(['query' => $query]));
    }
}

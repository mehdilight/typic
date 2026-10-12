<?php

declare(strict_types=1);

namespace Typic\Tests;

use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Typic\Subscription\PubSub\InMemoryPubSub;
use Typic\Tests\HttpFixtures\BrokenResolver;
use Typic\Tests\HttpFixtures\ExplodingMiddleware;
use Typic\Tests\MiddlewareFixtures\HandshakeConnection;
use Typic\Tests\MiddlewareFixtures\Secret;
use Typic\Tests\Support\MemoryLogger;
use Typic\Tests\Support\NullDriver;
use Typic\Tests\SubscriptionFixtures\CrashingSubscription;
use Typic\Typic;

class ErrorReportingTest extends TestCase
{
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
    }

    public function test_unexpected_errors_are_logged_with_the_exception(): void
    {
        $server = Typic::builder()->registerResolvers([BrokenResolver::class])->withLogger($this->logger)->build();

        $result = $server->execute('query Boom { crash }');

        $this->assertSame('Internal server error', $result['errors'][0]['message']);
        $this->assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        $this->assertSame(LogLevel::ERROR, $record['level']);
        $this->assertSame('GraphQL error in crash: Database is on fire', $record['message']);
        $this->assertInstanceOf(RuntimeException::class, $record['context']['exception']);
        $this->assertSame('Boom', $record['context']['operation']);
    }

    public function test_errors_meant_for_the_client_are_not_logged(): void
    {
        $server = Typic::builder()->registerResolvers([BrokenResolver::class])->withLogger($this->logger)->build();

        $server->execute('{ refuse }');
        $server->execute('{ checked(name: "x") }');
        $server->execute('{ nope }');

        $this->assertSame([], $this->logger->records);
    }

    public function test_failures_outside_graphql_become_a_logged_500(): void
    {
        $server = Typic::builder()
            ->registerResolvers([BrokenResolver::class])
            ->withMiddleware(new ExplodingMiddleware())
            ->withLogger($this->logger)
            ->build();

        $response = $server->handle(new ServerRequest('POST', '/graphql', ['Content-Type' => 'application/json'], '{"query":"{ refuse }"}'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['errors' => [['message' => 'Internal server error']]], json_decode((string) $response->getBody(), true));
        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertInstanceOf(LogicException::class, $this->logger->records[0]['context']['exception']);
    }

    public function test_subscription_errors_are_logged(): void
    {
        $pubSub = new InMemoryPubSub();
        $server = Typic::builder()
            ->registerResolvers([CrashingSubscription::class])
            ->registerTypes([Secret::class])
            ->withSubscriptions(driver: new NullDriver(), pubSub: $pubSub)
            ->withLogger($this->logger)
            ->build();
        $handler = $server->getSubscriptionServer()?->getProtocolHandler();
        $this->assertNotNull($handler);

        $connection = new HandshakeConnection();
        $handler->handleConnect($connection);
        $handler->handleMessage($connection, (string) json_encode(['type' => 'connection_init']));
        $handler->handleMessage($connection, (string) json_encode(['id' => '1', 'type' => 'subscribe', 'payload' => ['query' => 'subscription { crashing { id } }']]));
        $pubSub->publish('CRASH', new Secret(1, 'a', 'b'));

        $this->assertSame('Internal server error', $connection->received[1]['payload']['errors'][0]['message']);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('GraphQL error in crashing: Subscriber exploded', $this->logger->records[0]['message']);
    }
}

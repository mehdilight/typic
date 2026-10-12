<?php

declare(strict_types=1);

namespace Typic\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Typic\Subscription\Protocol\GraphqlWsHandler;
use Typic\Subscription\PubSub\InMemoryPubSub;
use Typic\Subscription\SubscriptionRegistry;
use Typic\Tests\MiddlewareFixtures\HandshakeConnection;
use Typic\Tests\MiddlewareFixtures\Secret;
use Typic\Tests\MiddlewareFixtures\SecretResolver;
use Typic\Tests\MiddlewareFixtures\Trace;
use Typic\Typic;

class SubscriptionMiddlewareTest extends TestCase
{
    private InMemoryPubSub $pubSub;
    private GraphqlWsHandler $handler;

    protected function setUp(): void
    {
        Trace::$log = [];

        $schema = Typic::builder()
            ->registerResolvers([SecretResolver::class])
            ->registerTypes([Secret::class])
            ->build()
            ->getSchema();

        $this->pubSub = new InMemoryPubSub();
        $this->handler = new GraphqlWsHandler(
            schema: $schema,
            registry: SubscriptionRegistry::fromResolvers([SecretResolver::class]),
            pubSub: $this->pubSub,
        );
    }

    public function test_subscribing_is_refused_when_middleware_throws(): void
    {
        $connection = $this->connect();
        $this->subscribe($connection, 'subscription { secretAdded { id text } }');

        $this->assertSame('error', $connection->received[1]['type']);
        $this->assertSame('sub_1', $connection->received[1]['id']);
        $this->assertSame('You must be logged in.', $connection->received[1]['payload'][0]['message']);
        $this->assertSame([], $this->handler->getActiveSubscriptions()[$connection->getId()] ?? []);

        // Nothing is delivered later either
        $this->pubSub->publish('SECRET_ADDED', new Secret(1, 'alice', 'hidden'));
        $this->assertCount(2, $connection->received);
        $this->assertSame(['subscribe'], Trace::$log);
    }

    public function test_connection_params_reach_middleware_which_runs_on_subscribe_and_every_event(): void
    {
        $connection = $this->connect(['Authorization' => 'Bearer alice-token']);
        $this->subscribe($connection, 'subscription { secretAdded { id text } }');

        $this->assertCount(1, $connection->received, 'Only the connection_ack, no error');
        $this->assertSame(['subscribe'], Trace::$log, 'The resolver does not run when subscribing');

        $this->pubSub->publish('SECRET_ADDED', new Secret(1, 'bob', 'not for alice'));
        $this->pubSub->publish('SECRET_ADDED', new Secret(2, 'alice', 'for alice'));

        $this->assertCount(2, $connection->received, 'The resolver filtered out the first event');
        $this->assertSame('next', $connection->received[1]['type']);
        $this->assertSame(['secretAdded' => ['id' => 2, 'text' => 'for alice']], $connection->received[1]['payload']['data']);

        // One middleware run and one resolver call per event
        $this->assertSame(['subscribe', 'event', 'resolver for alice', 'event', 'resolver for alice'], Trace::$log);
    }

    public function test_the_websocket_handshake_request_reaches_middleware(): void
    {
        $handshake = new ServerRequest('GET', '/graphql', ['Authorization' => 'Bearer bob-token']);
        $connection = $this->connect(connection: new HandshakeConnection($handshake));
        $this->subscribe($connection, 'subscription { secretAdded { text } }');

        $this->pubSub->publish('SECRET_ADDED', new Secret(1, 'bob', 'for bob'));

        $this->assertSame(['secretAdded' => ['text' => 'for bob']], $connection->received[1]['payload']['data']);
    }

    public function test_errors_from_middleware_during_an_event_reach_the_client(): void
    {
        $connection = $this->connect();
        $this->subscribe($connection, 'subscription { blocked { id } }');
        $this->assertCount(1, $connection->received);

        $this->pubSub->publish('SECRET_ADDED', new Secret(1, 'alice', 'text'));

        $this->assertSame('next', $connection->received[1]['type']);
        $this->assertSame('Event blocked', $connection->received[1]['payload']['errors'][0]['message']);
    }

    public function test_invalid_subscriptions_are_refused_when_subscribing(): void
    {
        $connection = $this->connect(['Authorization' => 'Bearer alice-token']);
        $this->subscribe($connection, 'subscription { secretAdded { nope } }');

        $this->assertSame('error', $connection->received[1]['type']);
        $this->assertStringContainsString('nope', $connection->received[1]['payload'][0]['message']);
        $this->assertSame([], $this->handler->getActiveSubscriptions()[$connection->getId()] ?? []);
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private function connect(?array $params = null, ?HandshakeConnection $connection = null): HandshakeConnection
    {
        $connection ??= new HandshakeConnection();
        $this->handler->handleConnect($connection);

        $init = ['type' => 'connection_init'];
        if ($params !== null) {
            $init['payload'] = $params;
        }
        $this->handler->handleMessage($connection, (string) json_encode($init));
        $this->assertSame('connection_ack', $connection->received[0]['type']);

        return $connection;
    }

    private function subscribe(HandshakeConnection $connection, string $query): void
    {
        $this->handler->handleMessage($connection, (string) json_encode([
            'id' => 'sub_1',
            'type' => 'subscribe',
            'payload' => ['query' => $query],
        ]));
    }
}

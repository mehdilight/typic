<?php

declare(strict_types=1);

namespace Typic\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Typic\Subscription\Protocol\GraphqlWsHandler;
use Typic\Subscription\PubSub\InMemoryPubSub;
use Typic\Subscription\SubscriptionOptions;
use Typic\Tests\MiddlewareFixtures\HandshakeConnection;
use Typic\Tests\MiddlewareFixtures\Secret;
use Typic\Tests\SubscriptionFixtures\OpenSubscription;
use Typic\Tests\Support\NullDriver;
use Typic\Typic;

class SubscriptionProtocolTest extends TestCase
{
    private float $now = 1000.0;

    public function test_idle_connections_are_pinged_then_closed(): void
    {
        $handler = $this->handler(new SubscriptionOptions(keepAlive: 10));
        $quiet = $this->connected($handler);
        $chatty = $this->connected($handler);

        $this->now += 10;
        $handler->tick($this->now);
        $this->assertSame(['type' => 'ping'], end($quiet->received));

        $handler->handleMessage($chatty, '{"type":"pong"}');
        $this->now += 11;
        $handler->tick($this->now);

        // No message for two intervals: gone. Answered the ping: kept.
        $this->assertSame([1001, 'Connection idle'], $quiet->closedWith);
        $this->assertNull($chatty->closedWith);
    }

    public function test_connections_must_initialise_in_time(): void
    {
        $handler = $this->handler(new SubscriptionOptions(connectionInitTimeout: 3));
        $slow = new HandshakeConnection();
        $handler->handleConnect($slow);

        $this->now += 2;
        $handler->tick($this->now);
        $this->assertNull($slow->closedWith);

        $this->now += 2;
        $handler->tick($this->now);
        $this->assertSame([4408, 'Connection initialisation timeout'], $slow->closedWith);
    }

    public function test_protocol_violations_close_the_connection_with_the_spec_code(): void
    {
        $handler = $this->handler();

        $early = new HandshakeConnection();
        $handler->handleConnect($early);
        $this->subscribe($handler, $early, '1');
        $this->assertSame([4401, 'Unauthorized'], $early->closedWith);

        $twice = $this->connected($handler);
        $handler->handleMessage($twice, '{"type":"connection_init"}');
        $this->assertSame([4429, 'Too many initialisation requests'], $twice->closedWith);

        $duplicate = $this->connected($handler);
        $this->subscribe($handler, $duplicate, 'same');
        $this->subscribe($handler, $duplicate, 'same');
        $this->assertSame([4409, 'Subscriber for same already exists'], $duplicate->closedWith);

        $garbage = $this->connected($handler);
        $handler->handleMessage($garbage, 'not json');
        $this->assertSame([4400, 'Invalid message'], $garbage->closedWith);
    }

    public function test_connection_init_can_be_refused(): void
    {
        $seen = [];
        $handler = $this->handler(new SubscriptionOptions(
            onConnectionInit: static function (array $params, ?ServerRequestInterface $request) use (&$seen): bool {
                $seen[] = [$params, $request?->getHeaderLine('Cookie')];
                return ($params['token'] ?? null) === 'letmein';
            },
        ));

        $refused = new HandshakeConnection(new ServerRequest('GET', '/graphql', ['Cookie' => 'sid=1']));
        $handler->handleConnect($refused);
        $handler->handleMessage($refused, '{"type":"connection_init","payload":{"token":"wrong"}}');
        $this->assertSame([4403, 'Forbidden'], $refused->closedWith);
        $this->assertSame([[['token' => 'wrong'], 'sid=1']], $seen);

        $accepted = new HandshakeConnection();
        $handler->handleConnect($accepted);
        $handler->handleMessage($accepted, '{"type":"connection_init","payload":{"token":"letmein"}}');
        $this->assertSame('connection_ack', $accepted->received[0]['type']);
    }

    public function test_subscriptions_per_connection_are_limited(): void
    {
        $handler = $this->handler(new SubscriptionOptions(maxSubscriptionsPerConnection: 2));
        $connection = $this->connected($handler);

        $this->subscribe($handler, $connection, 'a');
        $this->subscribe($handler, $connection, 'b');
        $this->subscribe($handler, $connection, 'c');

        $last = end($connection->received);
        $this->assertIsArray($last);
        $this->assertSame(['type' => 'error', 'id' => 'c', 'payload' => [['message' => 'Too many subscriptions: at most 2 per connection.']]], [
            'type' => $last['type'],
            'id' => $last['id'],
            'payload' => $last['payload'],
        ]);
        $this->assertCount(2, $handler->getActiveSubscriptions()[$connection->getId()]);
        $this->assertNull($connection->closedWith);

        // Completing one frees a slot
        $handler->handleMessage($connection, '{"type":"complete","id":"a"}');
        $this->subscribe($handler, $connection, 'c');
        $this->assertCount(2, $handler->getActiveSubscriptions()[$connection->getId()]);
    }

    public function test_the_builder_passes_options_through(): void
    {
        $server = Typic::builder()
            ->registerResolvers([OpenSubscription::class])
            ->registerTypes([Secret::class])
            ->withSubscriptions(driver: new NullDriver(), pubSub: new InMemoryPubSub(), options: new SubscriptionOptions(maxSubscriptionsPerConnection: 1))
            ->build();
        $handler = $server->getSubscriptionServer()?->getProtocolHandler();
        $this->assertNotNull($handler);

        $connection = $this->connected($handler);
        $this->subscribe($handler, $connection, 'a');
        $this->subscribe($handler, $connection, 'b');

        $last = end($connection->received);
        $this->assertIsArray($last);
        $this->assertSame('Too many subscriptions: at most 1 per connection.', $last['payload'][0]['message']);
    }

    public function test_invalid_message_shapes_close_instead_of_throwing(): void
    {
        foreach ([
            '{"type":"unknown"}',
            '{"type":"connection_init","payload":[]}',
            '{"type":"subscribe","id":12,"payload":{"query":"subscription { secrets { id } }"}}',
            '{"type":"subscribe","id":"a","payload":{"query":12}}',
            '{"type":"subscribe","id":"a","payload":{"query":"x","variables":[]}}',
            '{"type":"complete"}',
        ] as $message) {
            $handler = $this->handler();
            $connection = $this->connected($handler);
            $handler->handleMessage($connection, $message);
            $this->assertSame([4400, 'Invalid message'], $connection->closedWith, $message);
        }
    }

    public function test_closed_connections_release_subscriptions_and_ignore_later_messages(): void
    {
        $handler = $this->handler(new SubscriptionOptions(keepAlive: 1));
        $connection = $this->connected($handler);
        $this->subscribe($handler, $connection, 'a');
        $this->now += 3;
        $handler->tick();
        $this->assertSame([], $handler->getActiveSubscriptions());
        $received = $connection->received;
        $handler->handleMessage($connection, '{"type":"connection_init"}');
        $this->assertSame($received, $connection->received);
    }

    public function test_ping_echoes_the_payload(): void
    {
        $handler = $this->handler();
        $connection = $this->connected($handler);
        $handler->handleMessage($connection, '{"type":"ping","payload":{"nonce":123}}');
        $this->assertSame(['type' => 'pong', 'payload' => ['nonce' => 123]], end($connection->received));
    }

    public function test_builder_applies_the_request_size_limit_to_websocket_messages(): void
    {
        $server = Typic::builder()->registerResolvers([OpenSubscription::class])
            ->withMaxRequestSize(100)
            ->withSubscriptions(driver: new NullDriver(), pubSub: new InMemoryPubSub())->build();
        $handler = $server->getSubscriptionServer()?->getHandler();
        $this->assertNotNull($handler);
        $connection = $this->connected($handler);
        $handler->handleMessage($connection, str_repeat('x', 101));
        $this->assertSame([1009, 'Message too large'], $connection->closedWith);
    }

    private function handler(?SubscriptionOptions $options = null): GraphqlWsHandler
    {
        $schema = Typic::builder()->registerResolvers([OpenSubscription::class])->registerTypes([Secret::class])->build()->getSchema();

        return new GraphqlWsHandler(
            schema: $schema,
            registry: \Typic\Subscription\SubscriptionRegistry::fromResolvers([OpenSubscription::class]),
            pubSub: new InMemoryPubSub(),
            options: $options ?? new SubscriptionOptions(),
            clock: fn (): float => $this->now,
        );
    }

    private function connected(GraphqlWsHandler $handler): HandshakeConnection
    {
        $connection = new HandshakeConnection();
        $handler->handleConnect($connection);
        $handler->handleMessage($connection, '{"type":"connection_init"}');

        return $connection;
    }

    private function subscribe(GraphqlWsHandler $handler, HandshakeConnection $connection, string $id): void
    {
        $handler->handleMessage($connection, (string) json_encode([
            'id' => $id,
            'type' => 'subscribe',
            'payload' => ['query' => 'subscription { secrets { id } }'],
        ]));
    }
}

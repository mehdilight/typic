<?php

declare(strict_types=1);

namespace Typic\Tests;

use PHPUnit\Framework\TestCase;
use Typic\Attribute\ID;
use Typic\Attribute\ObjectType;
use Typic\Attribute\Subscription;
use Typic\Subscription\Driver\WebSocketConnectionInterface;
use Typic\Subscription\Driver\WebSocketServerInterface;
use Typic\Subscription\Driver\Workerman\HttpWebSocketProtocol;
use Typic\Subscription\Driver\Workerman\WorkermanDriver;
use Typic\Subscription\Protocol\GraphqlWsHandler;
use Typic\Subscription\PubSub\InMemoryPubSub;
use Typic\Subscription\SubscriptionRegistry;
use Typic\Subscription\SubscriptionServer;
use Typic\Typic;
use Workerman\Protocols\Http\Request as WorkermanRequest;

#[ObjectType]
class TestComment
{
    public function __construct(
        #[ID] public int $id,
        public int $postId,
        public string $body,
    ) {
    }
}

class TestSubscriptionResolver
{
    #[Subscription(
        name: 'commentAdded',
        type: TestComment::class,
        topic: 'COMMENT_ADDED',
        description: 'New comment posted'
    )]
    public function commentAdded(#[ID] int $postId, TestComment $payload): bool
    {
        return $payload->postId === $postId;
    }
}

class TestWebSocketConnection implements WebSocketConnectionInterface
{
    /** @var list<string> */
    public array $sentMessages = [];
    public bool $closed = false;

    public function __construct(private string $id = 'conn_1')
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function send(string $data): void
    {
        $this->sentMessages[] = $data;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->closed = true;
    }
}

class TestWebSocketDriver implements WebSocketServerInterface
{
    /** @var (callable(WebSocketConnectionInterface): void)|null */
    public $onConnect = null;

    /** @var (callable(WebSocketConnectionInterface, string): void)|null */
    public $onMessage = null;

    /** @var (callable(WebSocketConnectionInterface): void)|null */
    public $onClose = null;

    /** @var (callable(WebSocketConnectionInterface, \Throwable): void)|null */
    public $onError = null;

    public bool $started = false;

    public function onConnect(callable $callback): self
    {
        $this->onConnect = $callback;
        return $this;
    }

    public function onMessage(callable $callback): self
    {
        $this->onMessage = $callback;
        return $this;
    }

    public function onClose(callable $callback): self
    {
        $this->onClose = $callback;
        return $this;
    }

    public function onError(callable $callback): self
    {
        $this->onError = $callback;
        return $this;
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function stop(): void
    {
        $this->started = false;
    }

    public function getHost(): string
    {
        return '127.0.0.1';
    }

    public function getPort(): int
    {
        return 9099;
    }
}

class SubscriptionTest extends TestCase
{
    public function test_in_memory_pubsub(): void
    {
        $pubSub = new InMemoryPubSub();
        $received = [];

        $token = $pubSub->subscribe('CHAT_MESSAGE', function ($payload) use (&$received): void {
            $received[] = $payload;
        });

        $pubSub->publish('CHAT_MESSAGE', 'hello world');
        $pubSub->publish('CHAT_MESSAGE', 'second message');
        $pubSub->publish('OTHER_TOPIC', 'should be ignored');

        $this->assertCount(2, $received);
        $this->assertSame('hello world', $received[0]);
        $this->assertSame('second message', $received[1]);

        $pubSub->unsubscribe($token);
        $pubSub->publish('CHAT_MESSAGE', 'after unsubscribe');
        $this->assertCount(2, $received);
    }

    public function test_subscription_registry_indexes_resolvers(): void
    {
        $registry = SubscriptionRegistry::fromResolvers([TestSubscriptionResolver::class]);

        $this->assertTrue($registry->has('commentAdded'));
        $meta = $registry->get('commentAdded');
        $this->assertNotNull($meta);
        $this->assertSame('COMMENT_ADDED', $meta['topic']);
        $this->assertSame('commentAdded', $meta['fieldName']);
        $this->assertSame(TestSubscriptionResolver::class, $meta['resolverClass']);
    }

    public function test_graphql_ws_protocol_and_event_distribution(): void
    {
        $server = Typic::builder()
            ->registerResolvers([TestSubscriptionResolver::class])
            ->registerTypes([TestComment::class])
            ->build();

        $schema = $server->getSchema();
        $pubSub = new InMemoryPubSub();
        $registry = SubscriptionRegistry::fromResolvers([TestSubscriptionResolver::class]);

        $handler = new GraphqlWsHandler(
            schema: $schema,
            registry: $registry,
            pubSub: $pubSub,
        );

        $conn = new TestWebSocketConnection('client_123');
        $handler->handleConnect($conn);

        // 1. connection_init -> connection_ack
        $handler->handleMessage($conn, (string) json_encode(['type' => 'connection_init']));
        $this->assertCount(1, $conn->sentMessages);
        $ack = json_decode($conn->sentMessages[0], true);
        $this->assertSame('connection_ack', $ack['type']);

        // 2. ping -> pong
        $handler->handleMessage($conn, (string) json_encode(['type' => 'ping']));
        $this->assertCount(2, $conn->sentMessages);
        $pong = json_decode($conn->sentMessages[1], true);
        $this->assertSame('pong', $pong['type']);

        // 3. subscribe to commentAdded for postId: 1
        $subMessage = (string) json_encode([
            'id' => 'sub_1',
            'type' => 'subscribe',
            'payload' => [
                'query' => 'subscription OnComment($postId: ID!) { commentAdded(postId: $postId) { id body postId } }',
                'variables' => ['postId' => 1],
            ],
        ]);
        $handler->handleMessage($conn, $subMessage);

        $this->assertCount(1, $handler->getActiveSubscriptions()['client_123']);

        // 4. Publish event for postId: 2 (should be filtered out by resolver!)
        $pubSub->publish('COMMENT_ADDED', new TestComment(101, 2, 'Comment for post 2'));
        // Message count remains 2 (filtered out)
        $this->assertCount(2, $conn->sentMessages);

        // 5. Publish event for postId: 1 (should match and emit next frame!)
        $pubSub->publish('COMMENT_ADDED', new TestComment(102, 1, 'Live update for post 1!'));
        $this->assertCount(3, $conn->sentMessages);

        $nextFrame = json_decode($conn->sentMessages[2], true);
        $this->assertSame('sub_1', $nextFrame['id']);
        $this->assertSame('next', $nextFrame['type']);
        $this->assertSame('102', (string) $nextFrame['payload']['data']['commentAdded']['id']);
        $this->assertSame('Live update for post 1!', $nextFrame['payload']['data']['commentAdded']['body']);
        $this->assertSame(1, $nextFrame['payload']['data']['commentAdded']['postId']);

        // 6. Complete subscription
        $handler->handleMessage($conn, (string) json_encode([
            'id' => 'sub_1',
            'type' => 'complete',
        ]));
        $this->assertEmpty($handler->getActiveSubscriptions()['client_123'] ?? []);

        // 7. Publish again -> no new messages
        $pubSub->publish('COMMENT_ADDED', new TestComment(103, 1, 'After unsubscribe'));
        $this->assertCount(3, $conn->sentMessages);
    }

    public function test_driver_based_subscription_server(): void
    {
        $server = Typic::builder()
            ->registerResolvers([TestSubscriptionResolver::class])
            ->registerTypes([TestComment::class])
            ->build();

        $driver = new TestWebSocketDriver();
        $pubSub = new InMemoryPubSub();
        $registry = SubscriptionRegistry::fromResolvers([TestSubscriptionResolver::class]);

        $subServer = new SubscriptionServer(
            schema: $server->getSchema(),
            registry: $registry,
            driver: $driver,
            pubSub: $pubSub,
        );

        $this->assertSame($driver, $subServer->getDriver());
        $this->assertSame($pubSub, $subServer->getPubSub());

        $subServer->start();
        $this->assertTrue($driver->started);

        $subServer->stop();
        $this->assertFalse($driver->started);
    }

    public function test_workerman_driver_configuration(): void
    {
        $workermanDriver = new WorkermanDriver(
            host: '127.0.0.1',
            port: 8088,
            workerCount: 4,
            workerName: 'TypicTestWorkers',
        );

        $this->assertSame('127.0.0.1', $workermanDriver->getHost());
        $this->assertSame(8088, $workermanDriver->getPort());

        $worker = $workermanDriver->getWorker();
        $this->assertSame(4, $worker->count);
        $this->assertSame('TypicTestWorkers', $worker->name);
        $this->assertSame('tcp://127.0.0.1:8088', $worker->getSocketName());
        $this->assertSame(HttpWebSocketProtocol::class, $worker->protocol);
    }

    public function test_workerman_driver_serves_http_through_typic_server(): void
    {
        $driver = new WorkermanDriver(port: 8089);

        $server = Typic::builder()
            ->registerResolvers([TestSubscriptionResolver::class])
            ->registerTypes([TestComment::class])
            ->withSubscriptions(driver: $driver)
            ->build();

        // Same port serves HTTP and WebSocket, so the explorer subscribes on the HTTP path
        $this->assertSame('/graphql', $server->getOptions()['subscriptionEndpoint']);

        $body = (string) json_encode(['query' => '{ __typename }']);
        $request = new WorkermanRequest(
            "POST /graphql HTTP/1.1\r\n"
            . "Host: localhost:8089\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body
        );

        $response = $driver->handleHttpRequest($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => ['__typename' => 'Query']], json_decode($response->rawBody(), true));
    }

    public function test_explorer_can_be_switched_per_request(): void
    {
        $server = Typic::builder()
            ->registerResolvers([TestSubscriptionResolver::class])
            ->registerTypes([TestComment::class])
            ->withGraphiQL()
            ->build();

        $html = (string) $server->handle(
            new \Nyholm\Psr7\ServerRequest('GET', '/graphql?explorer=altair', ['Accept' => 'text/html'])
        )->getBody();
        $this->assertStringContainsString('altair', strtolower($html));
        $this->assertStringNotContainsString('graphiql.min.js', $html);

        $html = (string) $server->handle(
            new \Nyholm\Psr7\ServerRequest('GET', '/graphql', ['Accept' => 'text/html'])
        )->getBody();
        $this->assertStringContainsString('graphiql.min.js', $html);
    }

    public function test_typic_builder_with_subscriptions(): void
    {
        $driver = new TestWebSocketDriver();
        $pubSub = new InMemoryPubSub();

        $server = Typic::builder()
            ->registerResolvers([TestSubscriptionResolver::class])
            ->registerTypes([TestComment::class])
            ->withSubscriptions(
                driver: $driver,
                pubSub: $pubSub,
                endpoint: 'ws://localhost:9099'
            )
            ->build();

        $subServer = $server->getSubscriptionServer();
        $this->assertNotNull($subServer);
        $this->assertSame($driver, $subServer->getDriver());
        $this->assertSame($pubSub, $server->getPubSub());

        // Check sandbox HTML contains initialSubscriptionEndpoint
        $request = new \Nyholm\Psr7\ServerRequest('GET', '/graphql', ['Accept' => 'text/html']);
        $response = $server->handle($request);
        $html = (string) $response->getBody();

        $this->assertStringContainsString('initialSubscriptionEndpoint', $html);
        $this->assertStringContainsString('ws://localhost:9099', $html);
    }
}

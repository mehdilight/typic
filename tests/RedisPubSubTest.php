<?php

declare(strict_types=1);

namespace Typic\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Typic\Subscription\Driver\Workerman\WorkermanDriver;
use Typic\Subscription\PubSub\Redis\RedisSubscriberInterface;
use Typic\Subscription\PubSub\Redis\Resp;
use Typic\Subscription\PubSub\Redis\RespError;
use Typic\Subscription\PubSub\RedisPubSub;
use Typic\Subscription\SubscriptionRegistry;
use Typic\Subscription\SubscriptionServer;
use Typic\Tests\Fixtures\Live\LiveMessage;
use Typic\Tests\Fixtures\Live\LiveResolver;
use Typic\Typic;

class FakeRedisSubscriber implements RedisSubscriberInterface
{
    /** @var (callable(string, string): void)|null */
    public $handler = null;

    /** @var list<string> */
    public array $calls = [];

    public function onMessage(callable $handler): void
    {
        $this->handler = $handler;
    }

    public function subscribe(string $channel): void
    {
        $this->calls[] = "SUBSCRIBE {$channel}";
    }

    public function unsubscribe(string $channel): void
    {
        $this->calls[] = "UNSUBSCRIBE {$channel}";
    }

    public function deliver(string $channel, string $message): void
    {
        $this->handler !== null && ($this->handler)($channel, $message);
    }
}

class RedisPubSubTest extends TestCase
{
    public function test_resp_encodes_commands(): void
    {
        $this->assertSame("*2\r\n\$7\r\nPUBLISH\r\n\$0\r\n\r\n", Resp::encode(['PUBLISH', '']));
    }

    public function test_resp_parses_replies_and_waits_for_complete_frames(): void
    {
        $message = "*3\r\n\$7\r\nmessage\r\n\$5\r\ntopic\r\n\$12\r\nhello\r\nworld\r\n";

        $this->assertSame([['message', 'topic', "hello\r\nworld"], strlen($message)], Resp::parse($message));
        // Any truncation is reported as incomplete rather than misparsed
        for ($cut = 1; $cut < strlen($message); $cut++) {
            $this->assertNull(Resp::parse(substr($message, 0, $cut)), "Cut at {$cut}");
        }

        $this->assertSame(['OK', 5], Resp::parse("+OK\r\n"));
        $this->assertSame([3, 4], Resp::parse(":3\r\n"));
        $this->assertSame([null, 5], Resp::parse("\$-1\r\n"));

        $error = Resp::parse("-NOAUTH Authentication required.\r\n");
        $this->assertNotNull($error);
        $this->assertInstanceOf(RespError::class, $error[0]);
        $this->assertSame('NOAUTH Authentication required.', $error[0]->message);
    }

    public function test_subscribes_to_redis_once_per_topic_and_dispatches_payload_objects(): void
    {
        $subscriber = new FakeRedisSubscriber();
        $pubSub = new RedisPubSub(prefix: 'app:', subscriber: $subscriber);

        $received = [];
        $first = $pubSub->subscribe('MESSAGE_SENT', function (mixed $payload) use (&$received): void {
            $received[] = $payload;
        });
        $second = $pubSub->subscribe('MESSAGE_SENT', function (): void {
        });
        $this->assertSame(['SUBSCRIBE app:MESSAGE_SENT'], $subscriber->calls);

        $subscriber->deliver('app:MESSAGE_SENT', serialize(new LiveMessage(7, 'hi')));
        $subscriber->deliver('other:MESSAGE_SENT', serialize(new LiveMessage(8, 'not ours')));

        $this->assertCount(1, $received);
        $this->assertInstanceOf(LiveMessage::class, $received[0]);
        $this->assertSame('hi', $received[0]->text);

        $pubSub->unsubscribe($first);
        $this->assertSame(['SUBSCRIBE app:MESSAGE_SENT'], $subscriber->calls);
        $pubSub->unsubscribe($second);
        $this->assertSame(['SUBSCRIBE app:MESSAGE_SENT', 'UNSUBSCRIBE app:MESSAGE_SENT'], $subscriber->calls);
    }

    public function test_from_url_rejects_unsupported_schemes(): void
    {
        $this->assertSame('custom:', RedisPubSub::fromUrl('redis://user:secret@localhost:6380', 'custom:')->getPrefix());

        $this->expectException(InvalidArgumentException::class);
        RedisPubSub::fromUrl('rediss://localhost:6380');
    }

    public function test_redis_is_the_default_backplane(): void
    {
        $server = Typic::builder()
            ->registerResolvers([LiveResolver::class])
            ->registerTypes([LiveMessage::class])
            ->build();

        $subServer = new SubscriptionServer(
            schema: $server->getSchema(),
            registry: SubscriptionRegistry::fromResolvers([LiveResolver::class]),
            driver: new WorkermanDriver(),
        );

        $this->assertInstanceOf(RedisPubSub::class, $subServer->getPubSub());
    }
}

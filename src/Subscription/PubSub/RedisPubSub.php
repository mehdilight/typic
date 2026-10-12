<?php

declare(strict_types=1);

namespace Typic\Subscription\PubSub;

use InvalidArgumentException;
use Typic\Subscription\Driver\Workerman\WorkermanRedisSubscriber;
use Typic\Subscription\PubSub\Redis\RedisClient;
use Typic\Subscription\PubSub\Redis\RedisSubscriberInterface;

/**
 * Pub/Sub backed by Redis, so events reach subscribers in every process and on every server.
 *
 * Payloads travel PHP-serialized, so resolvers receive the same objects that were published.
 * Only point it at a Redis instance you trust.
 */
class RedisPubSub implements PubSubInterface
{
    private RedisClient $publisher;
    private RedisSubscriberInterface $subscriber;

    /** Listener bookkeeping for this process */
    private InMemoryPubSub $local;

    /** @var array<string, string> Subscription token => topic */
    private array $tokenTopics = [];

    /**
     * @param bool|list<class-string> $allowedClasses Classes unserialize() may instantiate for payloads
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        ?string $username = null,
        ?string $password = null,
        private string $prefix = 'typic:',
        private bool|array $allowedClasses = true,
        ?RedisClient $publisher = null,
        ?RedisSubscriberInterface $subscriber = null,
    ) {
        $this->publisher = $publisher ?? new RedisClient($host, $port, $username, $password);
        $this->subscriber = $subscriber ?? new WorkermanRedisSubscriber($host, $port, $username, $password);
        $this->subscriber->onMessage($this->dispatch(...));
        $this->local = new InMemoryPubSub();
    }

    /**
     * Create from a URL such as redis://user:secret@127.0.0.1:6379.
     */
    public static function fromUrl(string $url, string $prefix = 'typic:'): self
    {
        $parts = parse_url($url);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['redis', 'tcp'], true)) {
            throw new InvalidArgumentException("Unsupported Redis URL '{$url}'. Expected redis://[user:password@]host[:port].");
        }

        return new self(
            host: $parts['host'] ?? '127.0.0.1',
            port: $parts['port'] ?? 6379,
            username: isset($parts['user']) && $parts['user'] !== '' ? rawurldecode($parts['user']) : null,
            password: isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            prefix: $prefix,
        );
    }

    /**
     * Default backplane: REDIS_URL from the environment, or a local Redis.
     */
    public static function fromEnvironment(): self
    {
        $url = getenv('REDIS_URL');

        return self::fromUrl(is_string($url) && $url !== '' ? $url : 'redis://127.0.0.1:6379');
    }

    public function publish(string $topic, mixed $payload): void
    {
        $this->publisher->command('PUBLISH', $this->prefix . $topic, serialize($payload));
    }

    public function subscribe(string $topic, callable $listener): string
    {
        $isFirstListener = !isset($this->local->subscriberCounts()[$topic]);

        $token = $this->local->subscribe($topic, $listener);
        $this->tokenTopics[$token] = $topic;

        if ($isFirstListener) {
            $this->subscriber->subscribe($this->prefix . $topic);
        }

        return $token;
    }

    public function unsubscribe(string $subscriptionId): void
    {
        $topic = $this->tokenTopics[$subscriptionId] ?? null;
        if ($topic === null) {
            return;
        }

        unset($this->tokenTopics[$subscriptionId]);
        $this->local->unsubscribe($subscriptionId);

        if (!isset($this->local->subscriberCounts()[$topic])) {
            $this->subscriber->unsubscribe($this->prefix . $topic);
        }
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    private function dispatch(string $channel, string $message): void
    {
        if (!str_starts_with($channel, $this->prefix)) {
            return;
        }

        $payload = unserialize($message, ['allowed_classes' => $this->allowedClasses]);
        $this->local->publish(substr($channel, strlen($this->prefix)), $payload);
    }
}

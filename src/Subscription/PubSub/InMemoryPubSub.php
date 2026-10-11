<?php

declare(strict_types=1);

namespace Typic\Subscription\PubSub;

class InMemoryPubSub implements PubSubInterface
{
    /**
     * @var array<string, array<string, callable(mixed): void>>
     */
    private array $topics = [];

    private int $idCounter = 0;

    public function publish(string $topic, mixed $payload): void
    {
        if (!isset($this->topics[$topic])) {
            return;
        }

        foreach ($this->topics[$topic] as $listener) {
            $listener($payload);
        }
    }

    public function subscribe(string $topic, callable $listener): string
    {
        $id = 'sub_' . (++$this->idCounter);
        $this->topics[$topic][$id] = $listener;
        return $id;
    }

    public function unsubscribe(string $subscriptionId): void
    {
        foreach ($this->topics as $topic => $listeners) {
            if (isset($listeners[$subscriptionId])) {
                unset($this->topics[$topic][$subscriptionId]);
                if (empty($this->topics[$topic])) {
                    unset($this->topics[$topic]);
                }
                break;
            }
        }
    }

    /**
     * Clears all topic subscriptions.
     */
    public function clear(): void
    {
        $this->topics = [];
    }

    /**
     * @return array<string, int> Map of topic name to subscriber count
     */
    public function subscriberCounts(): array
    {
        $counts = [];
        foreach ($this->topics as $topic => $listeners) {
            $counts[$topic] = count($listeners);
        }
        return $counts;
    }
}

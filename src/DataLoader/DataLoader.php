<?php

declare(strict_types=1);

namespace Typic\DataLoader;

use GraphQL\Deferred;
use RuntimeException;
use Throwable;
use Traversable;

/**
 * A batching and caching utility for solving the GraphQL N+1 problem in Typic.
 *
 * @template TKey
 * @template TValue
 */
class DataLoader
{
    /**
     * @var array<string, mixed>
     */
    private array $queue = [];

    /**
     * @var array<string, mixed>
     */
    private array $cache = [];

    protected bool $cacheEnabled = true;

    protected ?int $maxBatchSize = null;

    /**
     * @var (callable(mixed): string)|null
     */
    protected mixed $cacheKeyFn = null;

    /**
     * Property name or callback to extract key from each loaded item (one-to-one / belongsTo).
     *
     * @var string|(callable(mixed): mixed)|null
     */
    protected mixed $keyBy = null;

    /**
     * Property name or callback to group loaded items by foreign key (one-to-many / hasMany).
     *
     * @var string|(callable(mixed): mixed)|null
     */
    protected mixed $groupBy = null;

    /**
     * @var (callable(list<mixed>): (list<mixed>|array<array-key, mixed>|Traversable<mixed, mixed>))|null
     */
    private mixed $batchLoadFn = null;

    /**
     * @param (callable(list<mixed>): (list<mixed>|array<array-key, mixed>|Traversable<mixed, mixed>))|null $batchLoadFn
     * @param string|(callable(mixed): mixed)|null $keyBy Property name or callback to extract key from each loaded item
     * @param bool $cache Whether to cache results for the lifecycle of this loader
     * @param int|null $maxBatchSize Maximum number of keys to load in a single batch
     * @param (callable(mixed): string)|null $cacheKeyFn Custom cache key generator
     * @param string|(callable(mixed): mixed)|null $groupBy Property name or callback to group items by for one-to-many relations
     */
    public function __construct(
        ?callable $batchLoadFn = null,
        string|callable|null $keyBy = null,
        bool $cache = true,
        ?int $maxBatchSize = null,
        ?callable $cacheKeyFn = null,
        string|callable|null $groupBy = null,
    ) {
        if ($batchLoadFn !== null) {
            $this->batchLoadFn = $batchLoadFn;
        }
        if ($keyBy !== null) {
            $this->keyBy = $keyBy;
        }
        if ($groupBy !== null) {
            $this->groupBy = $groupBy;
        }
        $this->cacheEnabled = $cache;
        if ($maxBatchSize !== null) {
            $this->maxBatchSize = $maxBatchSize;
        }
        if ($cacheKeyFn !== null) {
            $this->cacheKeyFn = $cacheKeyFn;
        }
    }

    /**
     * Factory method for creating a DataLoader with a closure.
     *
     * @param callable(list<mixed>): (list<mixed>|array<array-key, mixed>|Traversable<mixed, mixed>) $batchLoadFn
     * @param string|(callable(mixed): mixed)|null $keyBy
     * @param bool $cache
     * @param int|null $maxBatchSize
     * @param (callable(mixed): string)|null $cacheKeyFn
     * @param string|(callable(mixed): mixed)|null $groupBy
     * @return self<mixed, mixed>
     */
    public static function create(
        callable $batchLoadFn,
        string|callable|null $keyBy = null,
        bool $cache = true,
        ?int $maxBatchSize = null,
        ?callable $cacheKeyFn = null,
        string|callable|null $groupBy = null,
    ): self {
        return new self($batchLoadFn, $keyBy, $cache, $maxBatchSize, $cacheKeyFn, $groupBy);
    }

    /**
     * Get the property or callback to key results by.
     *
     * @return string|(callable(mixed): mixed)|null
     */
    protected function keyBy(): mixed
    {
        if ($this->keyBy !== null) {
            return $this->keyBy;
        }

        $attrs = (new \ReflectionClass($this))->getAttributes(\Typic\Attribute\KeyBy::class);
        return !empty($attrs) ? $attrs[0]->newInstance()->key : null;
    }

    /**
     * Get the property or callback to group results by.
     *
     * @return string|(callable(mixed): mixed)|null
     */
    protected function groupBy(): mixed
    {
        if ($this->groupBy !== null) {
            return $this->groupBy;
        }

        $attrs = (new \ReflectionClass($this))->getAttributes(\Typic\Attribute\GroupBy::class);
        return !empty($attrs) ? $attrs[0]->newInstance()->key : null;
    }

    /**
     * Subclasses can override this method to perform batch loading.
     *
     * @param list<mixed> $keys
     * @return list<mixed>|array<array-key, mixed>|Traversable<mixed, mixed>
     */
    protected function batch(array $keys): iterable
    {
        if ($this->batchLoadFn === null) {
            throw new RuntimeException(sprintf(
                'DataLoader %s must either define a batchLoadFn in constructor or implement batch(array $keys)',
                static::class
            ));
        }

        return ($this->batchLoadFn)($keys);
    }

    /**
     * Enqueue a key to be loaded in the next batch.
     *
     * Returns a GraphQL\Deferred that resolves to the loaded value when executed.
     *
     * @param mixed $key
     * @return Deferred
     */
    public function load(mixed $key): Deferred
    {
        $cacheKey = $this->getCacheKey($key);

        if ($this->cacheEnabled && array_key_exists($cacheKey, $this->cache)) {
            $cached = $this->cache[$cacheKey];
            return new Deferred(function () use ($cached) {
                if ($cached instanceof Throwable) {
                    throw $cached;
                }
                return $cached;
            });
        }

        if (!array_key_exists($cacheKey, $this->queue)) {
            $this->queue[$cacheKey] = $key;
        }

        return new Deferred(function () use ($cacheKey) {
            $this->dispatch();

            if (array_key_exists($cacheKey, $this->cache)) {
                $result = $this->cache[$cacheKey];
                if ($result instanceof Throwable) {
                    throw $result;
                }
                return $result;
            }

            return null;
        });
    }

    /**
     * Enqueue multiple keys to be loaded in the next batch.
     *
     * @param list<mixed> $keys
     * @return Deferred
     */
    public function loadMany(array $keys): Deferred
    {
        foreach ($keys as $key) {
            $cacheKey = $this->getCacheKey($key);
            if (!$this->cacheEnabled || !array_key_exists($cacheKey, $this->cache)) {
                if (!array_key_exists($cacheKey, $this->queue)) {
                    $this->queue[$cacheKey] = $key;
                }
            }
        }

        return new Deferred(function () use ($keys): array {
            $this->dispatch();

            $values = [];
            foreach ($keys as $key) {
                $cacheKey = $this->getCacheKey($key);
                $cached = $this->cache[$cacheKey] ?? null;
                if ($cached instanceof Throwable) {
                    throw $cached;
                }
                $values[] = $cached;
            }

            return $values;
        });
    }

    /**
     * Prime the cache with an existing key-value pair.
     *
     * @param mixed $key
     * @param mixed $value
     * @return $this
     */
    public function prime(mixed $key, mixed $value): self
    {
        $cacheKey = $this->getCacheKey($key);

        if (!array_key_exists($cacheKey, $this->cache)) {
            $this->cache[$cacheKey] = $value;
        }

        return $this;
    }

    /**
     * Clear a key from the cache.
     *
     * @param mixed $key
     * @return $this
     */
    public function clear(mixed $key): self
    {
        $cacheKey = $this->getCacheKey($key);
        unset($this->cache[$cacheKey]);

        return $this;
    }

    /**
     * Clear the entire cache.
     *
     * @return $this
     */
    public function clearAll(): self
    {
        $this->cache = [];

        return $this;
    }

    /**
     * Dispatch all queued keys in one or more batches.
     */
    public function dispatch(): void
    {
        if (empty($this->queue)) {
            return;
        }

        $queue = array_values($this->queue);
        $this->queue = [];

        if ($this->maxBatchSize !== null && $this->maxBatchSize > 0 && count($queue) > $this->maxBatchSize) {
            $chunks = array_chunk($queue, max(1, $this->maxBatchSize));
            foreach ($chunks as $chunk) {
                $this->executeBatch($chunk);
            }
        } else {
            $this->executeBatch($queue);
        }
    }

    /**
     * @param list<mixed> $keys
     */
    private function executeBatch(array $keys): void
    {
        try {
            $result = $this->batch($keys);

            if ($result instanceof Traversable) {
                $result = iterator_to_array($result);
            }

            $results = (array) $result;

            $groupBy = $this->groupBy();
            $keyBy = $this->keyBy();

            if ($groupBy !== null) {
                $grouped = [];
                foreach ($keys as $k) {
                    $grouped[$this->getCacheKey($k)] = [];
                }

                foreach ($results as $item) {
                    $groupKey = $this->extractKey($item, $groupBy);
                    if ($groupKey !== null) {
                        $ck = $this->getCacheKey($groupKey);
                        if (array_key_exists($ck, $grouped)) {
                            $grouped[$ck][] = $item;
                        }
                    }
                }

                foreach ($keys as $k) {
                    $ck = $this->getCacheKey($k);
                    $this->cache[$ck] = $grouped[$ck] ?? [];
                }
            } elseif ($keyBy !== null) {
                $keyed = [];
                foreach ($results as $item) {
                    $itemKey = $this->extractKey($item, $keyBy);
                    if ($itemKey !== null) {
                        $keyed[$this->getCacheKey($itemKey)] = $item;
                    }
                }

                foreach ($keys as $k) {
                    $ck = $this->getCacheKey($k);
                    $this->cache[$ck] = $keyed[$ck] ?? null;
                }
            } else {
                foreach ($keys as $index => $k) {
                    $ck = $this->getCacheKey($k);
                    $this->cache[$ck] = $results[$index] ?? null;
                }
            }
        } catch (Throwable $e) {
            foreach ($keys as $k) {
                $ck = $this->getCacheKey($k);
                $this->cache[$ck] = $e;
            }
        }
    }

    private function extractKey(mixed $item, mixed $keyExtractor): mixed
    {
        if (is_callable($keyExtractor)) {
            return $keyExtractor($item);
        }

        if (is_string($keyExtractor)) {
            if (is_object($item)) {
                if (isset($item->{$keyExtractor})) {
                    return $item->{$keyExtractor};
                }
                $getter = 'get' . ucfirst($keyExtractor);
                if (method_exists($item, $getter)) {
                    return $item->{$getter}();
                }
            }
            if (is_array($item) && array_key_exists($keyExtractor, $item)) {
                return $item[$keyExtractor];
            }
        }

        return null;
    }

    private function getCacheKey(mixed $key): string
    {
        if ($this->cacheKeyFn !== null) {
            return ($this->cacheKeyFn)($key);
        }

        if (is_scalar($key)) {
            return (string) $key;
        }

        if ($key === null) {
            return '__null__';
        }

        if (is_object($key)) {
            return spl_object_hash($key);
        }

        return serialize($key);
    }
}

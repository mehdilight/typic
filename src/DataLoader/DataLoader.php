<?php

declare(strict_types=1);

namespace Typic\DataLoader;

use Fiber;
use GraphQL\Deferred;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Batches lookups so a list of N items costs one query instead of N (the "N+1" problem).
 *
 * Write a loader by implementing batch(): it receives every key requested while resolving
 * the current level of the query, and returns the results keyed by those keys:
 *
 *     class UserLoader extends DataLoader
 *     {
 *         protected function batch(array $ids): iterable
 *         {
 *             return $this->keyBy(User::findMany($ids), 'id');     // [id => User]
 *         }
 *     }
 *
 * Then take the loader as a resolver parameter and call get():
 *
 *     #[ResolveField(target: Post::class)]
 *     public function author(Post $post, UserLoader $users): ?User
 *     {
 *         return $users->get($post->authorId);
 *     }
 *
 * get() looks synchronous, but Typic pauses the resolver until every sibling resolver has
 * asked for its key, then calls batch() once with all of them. Keys missing from the
 * result resolve to null. One loader instance lives per request, and results are cached.
 *
 * TValue is what get() returns: declare it nullable for one-to-one loaders (keys can be
 * missing), and as a list for one-to-many loaders built with groupBy().
 *
 * @template TKey
 * @template TValue
 */
class DataLoader
{
    /** Whether a key loaded earlier in the request is served from memory instead of being batched again. */
    protected bool $cache = true;

    /** Split large batches into chunks of this many keys. */
    protected ?int $maxBatchSize = null;

    /** @var array<string, mixed> Keys waiting for the next batch */
    private array $queue = [];

    /** @var array<string, mixed> Loaded values (or the Throwable a batch failed with) */
    private array $results = [];

    /** @var list<mixed> Keys of the batch currently running, for groupBy() */
    private array $batchKeys = [];

    /** @var (callable(list<TKey>): iterable<mixed, mixed>)|null */
    private $batchFn = null;

    /**
     * @param (callable(list<TKey>): iterable<mixed, mixed>)|null $batch Batch function, for loaders not written as subclasses
     */
    public function __construct(?callable $batch = null)
    {
        $this->batchFn = $batch;
    }

    /**
     * Create a loader from a batch function instead of a subclass.
     *
     * @param callable(list<mixed>): iterable<mixed, mixed> $batch
     * @return self<mixed, mixed>
     */
    public static function create(callable $batch): self
    {
        return new self($batch);
    }

    /**
     * Load every key at once. Return the results keyed by the requested keys;
     * keyBy() and groupBy() build that array for you.
     *
     * @param list<TKey> $keys
     * @return iterable<mixed, mixed>
     */
    protected function batch(array $keys): iterable
    {
        if ($this->batchFn === null) {
            throw new RuntimeException(sprintf('%s must implement batch(array $keys).', static::class));
        }

        return ($this->batchFn)($keys);
    }

    /**
     * Get the value for $key. Call it from a resolver that receives this loader as a parameter.
     *
     * @param TKey $key
     * @return TValue
     */
    public function get(mixed $key): mixed
    {
        $cacheKey = $this->cacheKey($key);

        if (!$this->cache || !array_key_exists($cacheKey, $this->results)) {
            $this->assertInsideResolver('get');
            $this->queue[$cacheKey] = $key;
            Fiber::suspend($this);
        }

        return $this->result($cacheKey);
    }

    /**
     * Get the values for several keys, in the same order.
     *
     * @param list<TKey> $keys
     * @return list<TValue>
     */
    public function getMany(array $keys): array
    {
        $missing = false;
        foreach ($keys as $key) {
            $cacheKey = $this->cacheKey($key);
            if (!$this->cache || !array_key_exists($cacheKey, $this->results)) {
                $this->queue[$cacheKey] = $key;
                $missing = true;
            }
        }

        if ($missing) {
            $this->assertInsideResolver('getMany');
            Fiber::suspend($this);
        }

        return array_map(fn (mixed $key): mixed => $this->result($this->cacheKey($key)), $keys);
    }

    /**
     * Promise-style alternative to get(): returns a Deferred resolver can return as-is.
     * Works anywhere, including resolvers that do not take the loader as a parameter.
     *
     * @param TKey $key
     */
    public function load(mixed $key): Deferred
    {
        $cacheKey = $this->cacheKey($key);

        if (!$this->cache || !array_key_exists($cacheKey, $this->results)) {
            $this->queue[$cacheKey] = $key;
        }

        return new Deferred(function () use ($cacheKey): mixed {
            $this->dispatch();
            return $this->result($cacheKey);
        });
    }

    /**
     * Promise-style alternative to getMany().
     *
     * @param list<TKey> $keys
     */
    public function loadMany(array $keys): Deferred
    {
        foreach ($keys as $key) {
            $cacheKey = $this->cacheKey($key);
            if (!$this->cache || !array_key_exists($cacheKey, $this->results)) {
                $this->queue[$cacheKey] = $key;
            }
        }

        return new Deferred(function () use ($keys): array {
            $this->dispatch();
            return array_map(fn (mixed $key): mixed => $this->result($this->cacheKey($key)), $keys);
        });
    }

    /**
     * Store a value you already have, so loading its key needs no query.
     *
     * @param TKey $key
     * @param TValue $value
     * @return $this
     */
    public function prime(mixed $key, mixed $value): self
    {
        $cacheKey = $this->cacheKey($key);
        if (!array_key_exists($cacheKey, $this->results)) {
            $this->results[$cacheKey] = $value;
        }

        return $this;
    }

    /**
     * Forget one cached key, for example after a mutation changed it.
     *
     * @param TKey $key
     * @return $this
     */
    public function clear(mixed $key): self
    {
        unset($this->results[$this->cacheKey($key)]);

        return $this;
    }

    /**
     * @return $this
     */
    public function clearAll(): self
    {
        $this->results = [];

        return $this;
    }

    /**
     * Run batch() for every queued key. Typic calls this for you.
     */
    public function dispatch(): void
    {
        if ($this->queue === []) {
            return;
        }

        $keys = array_values($this->queue);
        $this->queue = [];

        $chunks = $this->maxBatchSize !== null && $this->maxBatchSize > 0
            ? array_chunk($keys, $this->maxBatchSize)
            : [$keys];

        foreach ($chunks as $chunk) {
            $this->runBatch($chunk);
        }
    }

    /**
     * One-to-one helper: index items by a property (or callback), e.g. users by 'id'.
     *
     * @param iterable<mixed, mixed> $items
     * @param string|callable(mixed): mixed $key
     * @return array<array-key, mixed>
     */
    protected function keyBy(iterable $items, string|callable $key): array
    {
        $keyed = [];
        foreach ($items as $item) {
            $keyed[$this->cacheKey($this->extract($item, $key))] = $item;
        }

        return $keyed;
    }

    /**
     * One-to-many helper: collect items into lists by a property (or callback), e.g. comments
     * by 'postId'. Every requested key gets a list, empty when nothing matched.
     *
     * @param iterable<mixed, mixed> $items
     * @param string|callable(mixed): mixed $key
     * @return array<array-key, list<mixed>>
     */
    protected function groupBy(iterable $items, string|callable $key): array
    {
        $groups = [];
        foreach ($this->batchKeys as $requested) {
            $groups[$this->cacheKey($requested)] = [];
        }

        foreach ($items as $item) {
            $groups[$this->cacheKey($this->extract($item, $key))][] = $item;
        }

        return $groups;
    }

    /**
     * How keys are compared. Override for composite keys.
     *
     * @param TKey $key
     */
    protected function cacheKey(mixed $key): string
    {
        return match (true) {
            $key === null => "\0null",
            is_scalar($key) => (string) $key,
            $key instanceof \BackedEnum => (string) $key->value,
            is_object($key) => spl_object_hash($key),
            default => serialize($key),
        };
    }

    /**
     * @param list<mixed> $keys
     */
    private function runBatch(array $keys): void
    {
        $this->batchKeys = $keys;

        try {
            /** @var array<array-key, mixed> $loaded */
            $loaded = [];
            foreach ($this->batch($keys) as $key => $value) {
                $loaded[$this->cacheKey($key)] = $value;
            }
            $this->assertKeyedByRequestedKeys($keys, $loaded);

            foreach ($keys as $key) {
                $cacheKey = $this->cacheKey($key);
                $this->results[$cacheKey] = $loaded[$cacheKey] ?? null;
            }
        } catch (Throwable $e) {
            foreach ($keys as $key) {
                $this->results[$this->cacheKey($key)] = $e;
            }
        } finally {
            $this->batchKeys = [];
        }
    }

    /**
     * Catch the most common mistake: returning a plain list (0, 1, 2...) instead of results keyed by key.
     *
     * @param list<mixed> $keys
     * @param array<array-key, mixed> $loaded
     */
    private function assertKeyedByRequestedKeys(array $keys, array $loaded): void
    {
        if ($loaded === [] || !array_is_list($loaded)) {
            return;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($this->cacheKey($key), $loaded)) {
                throw new LogicException(sprintf(
                    '%s::batch() returned a list, but results must be keyed by the requested keys. '
                    . 'Use $this->keyBy($items, \'id\') for one-to-one or $this->groupBy($items, \'parentId\') for one-to-many.',
                    static::class,
                ));
            }
        }
    }

    private function result(string $cacheKey): mixed
    {
        $result = $this->results[$cacheKey] ?? null;
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    private function assertInsideResolver(string $method): void
    {
        if (!FiberResolver::isRunning()) {
            throw new LogicException(sprintf(
                '%1$s::%2$s() only works in a resolver that receives %1$s as a parameter. Elsewhere, return %1$s::load() instead.',
                static::class,
                $method,
            ));
        }
    }

    private function extract(mixed $item, string|callable $key): mixed
    {
        if (!is_string($key)) {
            return $key($item);
        }

        if (is_array($item)) {
            return $item[$key] ?? null;
        }

        if (is_object($item)) {
            return $item->{$key} ?? null;
        }

        return null;
    }
}

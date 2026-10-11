<?php

declare(strict_types=1);

namespace Typic;

use ArrayAccess;

/**
 * @implements ArrayAccess<string, mixed>
 */
class Context implements ArrayAccess
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data = [],
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[(string) $offset]);
    }
}

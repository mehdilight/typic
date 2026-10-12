<?php

declare(strict_types=1);

namespace Typic\Middleware;

use GraphQL\Type\Definition\ResolveInfo;
use Psr\Http\Message\ServerRequestInterface;
use Typic\Context;

/**
 * The field being resolved, as seen by resolver middleware.
 *
 * Calls are immutable: with() and withArgs() return a new call to pass to $next().
 */
final class ResolverCall
{
    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $values
     */
    public function __construct(
        private mixed $parent,
        private array $args,
        private mixed $context,
        private ResolveInfo $info,
        private array $values = [],
    ) {
    }

    /**
     * The object the field belongs to (null for Query, Mutation and Subscription fields).
     */
    public function parent(): mixed
    {
        return $this->parent;
    }

    /**
     * The GraphQL arguments, as sent by the client.
     *
     * @return array<string, mixed>
     */
    public function args(): array
    {
        return $this->args;
    }

    public function arg(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->args) ? $this->args[$name] : $default;
    }

    /**
     * Replace the arguments the resolver receives.
     *
     * @param array<string, mixed> $args
     */
    public function withArgs(array $args): self
    {
        $call = clone $this;
        $call->args = $args;

        return $call;
    }

    /**
     * Pass a value to the middleware after this one and to the resolver, which reads it
     * with a #[FromCall('key')] parameter.
     */
    public function with(string $key, mixed $value): self
    {
        $call = clone $this;
        $call->values[$key] = $value;

        return $call;
    }

    /**
     * A value set by an earlier middleware with with().
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * The field as "ParentType.field", e.g. "Mutation.createPost" or "Post.author".
     */
    public function field(): string
    {
        return $this->info->parentType->name . '.' . $this->info->fieldName;
    }

    /**
     * The HTTP request, or for WebSocket fields the HTTP request that opened the connection.
     * Null when the query was executed directly.
     */
    public function request(): ?ServerRequestInterface
    {
        $request = $this->context()->get('request');

        return $request instanceof ServerRequestInterface ? $request : null;
    }

    /**
     * The payload the client sent with connection_init, for fields resolved over WebSocket.
     * WebSocket clients in browsers cannot set headers, so they usually send tokens here.
     *
     * @return array<string, mixed>
     */
    public function connectionParams(): array
    {
        $params = $this->context()->get('connectionParams');

        return is_array($params) ? $params : [];
    }

    /**
     * True while a client subscribes to a subscription field. The resolver does not run then and
     * $next() returns null; the middleware runs again, with the resolver, for every event.
     */
    public function subscribing(): bool
    {
        return $this->context()->get('subscribing') === true;
    }

    public function context(): Context
    {
        return $this->context instanceof Context ? $this->context : new Context();
    }

    public function info(): ResolveInfo
    {
        return $this->info;
    }
}

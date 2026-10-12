<?php

declare(strict_types=1);

namespace Typic\Schema;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Creates resolvers and DataLoaders: from the container when it has the class, otherwise
 * by filling constructor parameters from the container, their defaults, or null.
 *
 * check() runs while the schema is built, so a missing dependency fails at startup with a
 * message naming the class and parameter, instead of on the first request that needs it.
 */
final class Instantiator
{
    public function __construct(private ?ContainerInterface $container = null)
    {
    }

    /**
     * @param class-string $class
     * @throws InvalidArgumentException When a constructor parameter cannot be filled
     */
    public function check(string $class): void
    {
        if ($this->container?->has($class) === true) {
            return;
        }

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
            $this->assertFillable($class, $param);
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function create(string $class): object
    {
        if ($this->container !== null && $this->container->has($class)) {
            /** @var T */
            return $this->container->get($class);
        }

        $ref = new ReflectionClass($class);
        $args = [];
        foreach ($ref->getConstructor()?->getParameters() ?? [] as $param) {
            $args[] = $this->fill($class, $param);
        }

        return $ref->newInstanceArgs($args);
    }

    /**
     * @param class-string $class
     */
    private function fill(string $class, ReflectionParameter $param): mixed
    {
        $type = $param->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->container?->has($type->getName()) === true) {
            return $this->container->get($type->getName());
        }
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }
        if ($param->allowsNull()) {
            return null;
        }

        $this->assertFillable($class, $param);

        return null;
    }

    /**
     * @param class-string $class
     */
    private function assertFillable(string $class, ReflectionParameter $param): void
    {
        $type = $param->getType();
        $inContainer = $type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->container?->has($type->getName()) === true;
        if ($inContainer || $param->isDefaultValueAvailable() || $param->allowsNull()) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot create %s: constructor parameter $%s (%s) is not in the container. '
            . 'Register it in the container, give it a default value, or make it nullable.',
            $class,
            $param->getName(),
            $type !== null ? (string) $type : 'no type',
        ));
    }
}

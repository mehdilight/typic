<?php

declare(strict_types=1);

namespace Typic\Schema;

use GraphQL\Type\Definition\ResolveInfo;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Typic\Attribute\Arg;
use Typic\Attribute\Context as ContextAttr;
use Typic\Attribute\FromCall;
use Typic\Attribute\Info as InfoAttr;
use Typic\Attribute\Inject;
use Typic\Attribute\InputType;
use Typic\Attribute\Payload as PayloadAttr;
use Typic\Context;
use Typic\DataLoader\DataLoader;
use Typic\Middleware\ResolverCall;
use Typic\Subscription\PubSub\PubSubInterface;

class ArgumentHydrator
{
    public function __construct(
        private ?ContainerInterface $container = null,
    ) {
    }

    /**
     * Resolves the list of arguments to pass to a method call.
     *
     * @param ReflectionMethod $method
     * @param array<string, mixed> $args
     * @param mixed $source
     * @param mixed $context
     * @param ResolveInfo $info
     * @param class-string|null $sourceClass
     * @param ResolverCall|null $call The call as the resolver's middleware left it, for #[FromCall]
     * @return list<mixed>
     */
    public function resolveArguments(
        ReflectionMethod $method,
        array $args,
        mixed $source,
        mixed $context,
        ResolveInfo $info,
        ?string $sourceClass = null,
        ?ResolverCall $call = null,
    ): array {
        $resolved = [];

        foreach ($method->getParameters() as $param) {
            $fromCall = $param->getAttributes(FromCall::class);
            $resolved[] = $fromCall !== []
                ? $this->resolveFromCall($param, $fromCall[0]->newInstance()->key, $call)
                : $this->resolveParameter($param, $args, $source, $context, $info, $sourceClass);
        }

        return $resolved;
    }

    private function resolveFromCall(ReflectionParameter $param, string $key, ?ResolverCall $call): mixed
    {
        if ($call !== null && $call->has($key)) {
            return $call->get($key);
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        $method = $param->getDeclaringFunction();
        throw new LogicException(sprintf(
            "%s::%s() takes #[FromCall('%s')] \$%s, but no middleware set it. "
            . "Add a middleware that calls \$call->with('%s', ...), or make the parameter nullable.",
            $method instanceof ReflectionMethod ? $method->getDeclaringClass()->getName() : '',
            $method->getName(),
            $key,
            $param->getName(),
            $key,
        ));
    }

    /**
     * @param array<string, mixed> $args
     * @param class-string|null $sourceClass
     */
    private function resolveParameter(
        ReflectionParameter $param,
        array $args,
        mixed $source,
        mixed $context,
        ResolveInfo $info,
        ?string $sourceClass = null,
    ): mixed {
        // 1. Context injection
        if (!empty($param->getAttributes(ContextAttr::class))) {
            return $context;
        }

        // 2. ResolveInfo injection
        if (!empty($param->getAttributes(InfoAttr::class))) {
            return $info;
        }

        // 3. Subscription payload injection
        if (!empty($param->getAttributes(PayloadAttr::class)) ||
            $param->getName() === 'payload' ||
            $param->getName() === 'event') {
            return $source;
        }

        // 4. Explicit container injection #[Inject]
        $injectAttrs = $param->getAttributes(Inject::class);
        if (!empty($injectAttrs)) {
            /** @var Inject $inject */
            $inject = $injectAttrs[0]->newInstance();
            $serviceId = $inject->id;
            if ($serviceId !== null && $this->container !== null && $this->container->has($serviceId)) {
                return $this->container->get($serviceId);
            }
        }

        $type = $param->getType();

        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();

            // Check if parameter is Context class
            if ($typeName === Context::class || is_subclass_of($typeName, Context::class)) {
                return $context;
            }

            // Check if parameter is ResolveInfo
            if ($typeName === ResolveInfo::class) {
                return $info;
            }

            // Check if parameter is PubSubInterface
            if ($typeName === PubSubInterface::class || is_subclass_of($typeName, PubSubInterface::class)) {
                if ($context instanceof Context && $context->has('pubSub')) {
                    return $context->get('pubSub');
                }
            }

            // Check if parameter matches source object (for ResolveField / Field resolvers)
            if ($source !== null && ($typeName === $sourceClass || (is_object($source) && is_a($source, $typeName)))) {
                return $source;
            }

            // Check if parameter is a DataLoader
            if (is_subclass_of($typeName, DataLoader::class)) {
                return $this->resolveDataLoader($typeName, $context);
            }

            // Check if parameter is a service in container
            if ($this->container !== null && $this->container->has($typeName)) {
                return $this->container->get($typeName);
            }
        }

        // 4. Resolve from GraphQL arguments ($args)
        $argName = $param->getName();
        $argAttrs = $param->getAttributes(Arg::class);
        if (!empty($argAttrs)) {
            /** @var Arg $argAttr */
            $argAttr = $argAttrs[0]->newInstance();
            if ($argAttr->name !== null) {
                $argName = $argAttr->name;
            }
        }

        if (array_key_exists($argName, $args)) {
            $rawVal = $args[$argName];
            return $this->castValueToType($rawVal, $type);
        }

        // Default value if parameter wasn't provided
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        return null;
    }

    /**
     * Recursively hydrates an associative array into an Input DTO class instance.
     *
     * @template T of object
     * @param class-string<T> $className
     * @param array<string, mixed> $data
     * @return T
     */
    public function hydrateInputObject(string $className, array $data): object
    {
        $refClass = new ReflectionClass($className);
        $constructor = $refClass->getConstructor();

        if ($constructor === null || empty($constructor->getParameters())) {
            $instance = $refClass->newInstance();
            foreach ($data as $key => $val) {
                if ($refClass->hasProperty($key)) {
                    $prop = $refClass->getProperty($key);
                    $propType = $prop->getType();
                    $val = $this->castValueToType($val, $propType);
                    $prop->setValue($instance, $val);
                }
            }
            return $instance;
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            $paramType = $param->getType();

            if (array_key_exists($name, $data)) {
                $val = $data[$name];
                $args[$name] = $this->castValueToType($val, $paramType);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[$name] = $param->getDefaultValue();
            } elseif ($paramType !== null && $paramType->allowsNull()) {
                $args[$name] = null;
            } else {
                $args[$name] = null;
            }
        }

        return $refClass->newInstanceArgs($args);
    }

    private function castValueToType(mixed $val, ?\ReflectionType $type): mixed
    {
        if ($val === null || !$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $val;
        }

        $className = $type->getName();

        // BackedEnum hydration
        if (is_subclass_of($className, \BackedEnum::class)) {
            if ($val instanceof $className) {
                return $val;
            }
            return $className::tryFrom($val) ?? $className::from($val);
        }

        // UnitEnum hydration
        if (is_subclass_of($className, \UnitEnum::class)) {
            if ($val instanceof $className) {
                return $val;
            }
            foreach ($className::cases() as $case) {
                if ($case->name === $val) {
                    return $case;
                }
            }
            return null;
        }

        // Nested Input Object hydration
        if (is_array($val)) {
            /** @var class-string $className */
            return $this->hydrateInputObject($className, $val);
        }

        return $val;
    }

    /**
     * @template T of DataLoader
     * @param class-string<T> $loaderClass
     * @param mixed $context
     * @return T
     */
    private function resolveDataLoader(string $loaderClass, mixed $context): DataLoader
    {
        // If context has dataloaders cache, use it
        if ($context instanceof Context) {
            $loaders = $context->get('__dataloaders', []);
            if (isset($loaders[$loaderClass])) {
                return $loaders[$loaderClass];
            }
        }

        // Check container
        if ($this->container !== null && $this->container->has($loaderClass)) {
            $loader = $this->container->get($loaderClass);
        } else {
            $ref = new ReflectionClass($loaderClass);
            $constructor = $ref->getConstructor();
            if ($constructor !== null && !empty($constructor->getParameters())) {
                $args = [];
                foreach ($constructor->getParameters() as $p) {
                    $pType = $p->getType();
                    if ($pType instanceof ReflectionNamedType && $this->container !== null && $this->container->has($pType->getName())) {
                        $args[] = $this->container->get($pType->getName());
                    } elseif ($p->isDefaultValueAvailable()) {
                        $args[] = $p->getDefaultValue();
                    } else {
                        $args[] = null;
                    }
                }
                $loader = $ref->newInstanceArgs($args);
            } else {
                $loader = new $loaderClass();
            }
        }

        if ($context instanceof Context) {
            $loaders = $context->get('__dataloaders', []);
            $loaders[$loaderClass] = $loader;
            $context->set('__dataloaders', $loaders);
        }

        return $loader;
    }
}

<?php

declare(strict_types=1);

namespace Typic\Schema;

use GraphQL\Type\Definition\ResolveInfo;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Typic\Attribute\Arg;
use Typic\Attribute\Context as ContextAttr;
use Typic\Attribute\FromCall;
use Typic\Attribute\FromRequest;
use Typic\Attribute\Info as InfoAttr;
use Typic\Attribute\Inject;
use Typic\Attribute\InputType;
use Typic\Attribute\Payload as PayloadAttr;
use Typic\Context;
use Typic\DataLoader\DataLoader;
use Typic\Input\PresenceAware;
use Typic\Middleware\ResolverCall;
use Typic\Subscription\PubSub\PubSubInterface;

class ArgumentHydrator
{
    private Instantiator $instantiator;

    /** @param (callable(class-string<DataLoader<mixed, mixed>>): DataLoader<mixed, mixed>)|null $dataLoaderFactory */
    public function __construct(
        private ?ContainerInterface $container = null,
        private mixed $dataLoaderFactory = null,
    ) {
        $this->instantiator = new Instantiator($container);
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
            $fromRequest = $param->getAttributes(FromRequest::class);
            $resolved[] = match (true) {
                $fromCall !== [] => $this->resolveFromCall($param, $fromCall[0]->newInstance()->key, $call),
                $fromRequest !== [] => $this->resolveFromRequest($param, $fromRequest[0]->newInstance()->name, $context),
                default => $this->resolveParameter($param, $args, $source, $context, $info, $sourceClass),
            };
        }

        return $resolved;
    }

    private function resolveFromRequest(ReflectionParameter $param, string $name, mixed $context): mixed
    {
        $request = $context instanceof Context ? $context->get('request') : null;
        $value = $request instanceof ServerRequestInterface ? $request->getAttribute($name) : null;
        if ($value !== null) {
            return $value;
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        $method = $param->getDeclaringFunction();
        throw new LogicException(sprintf(
            "%s::%s() takes #[FromRequest('%s')] \$%s, but the request has no '%s' attribute. "
            . "Add HTTP middleware (withMiddleware()) that sets it with \$request->withAttribute('%s', ...), or make the parameter nullable.",
            $method instanceof ReflectionMethod ? $method->getDeclaringClass()->getName() : '',
            $method->getName(),
            $name,
            $param->getName(),
            $name,
            $name,
        ));
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
            return $this->castValueToType($rawVal, $type, self::itemClass($param));
        }

        // Only parameters absent from the GraphQL schema are service dependencies.
        // Autowiring containers may report that input DTOs and scalars are constructible too.
        if ($type instanceof ReflectionNamedType && $this->container !== null
            && $info->parentType->getField($info->fieldName)->getArg($argName) === null
            && $this->container->has($type->getName())) {
            return $this->container->get($type->getName());
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

        $present = [];
        if ($constructor === null || empty($constructor->getParameters())) {
            $instance = $refClass->newInstance();
            foreach ($refClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                $key = self::graphQLName($prop);
                if (array_key_exists($key, $data)) {
                    $prop->setValue($instance, $this->castValueToType($data[$key], $prop->getType(), self::itemClass($prop)));
                    $present[] = $prop->getName();
                }
            }
        } else {
            $args = [];
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();
                $key = self::graphQLName($param);

                if (array_key_exists($key, $data)) {
                    $args[$name] = $this->castValueToType($data[$key], $param->getType(), self::itemClass($param));
                    $present[] = $name;
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[$name] = $param->getDefaultValue();
                } else {
                    $args[$name] = null;
                }
            }
            $instance = $refClass->newInstanceArgs($args);
        }

        // GraphQL coercion leaves omitted fields out of $data, so presence tells "omitted" from "null"
        if ($instance instanceof PresenceAware) {
            $instance->withPresentFields($present);
        }

        return $instance;
    }

    /**
     * The input field name: #[Field(name: ...)] when given, else the PHP name.
     */
    private static function graphQLName(ReflectionParameter|\ReflectionProperty $member): string
    {
        $attrs = $member->getAttributes(\Typic\Attribute\Field::class);

        return ($attrs !== [] ? $attrs[0]->newInstance()->name : null) ?? $member->getName();
    }

    /**
     * The class of a list's items, from #[Field(arrayOf: ...)] or #[Arg(arrayOf: ...)], when it is an enum or
     * input class rather than a scalar name such as 'string' or 'ID'.
     *
     * @return class-string|null
     */
    private static function itemClass(ReflectionParameter|\ReflectionProperty $member): ?string
    {
        $field = $member->getAttributes(\Typic\Attribute\Field::class);
        $arg = $member->getAttributes(Arg::class);
        $arrayOf = $field !== [] ? $field[0]->newInstance()->arrayOf : ($arg !== [] ? $arg[0]->newInstance()->arrayOf : null);

        return is_string($arrayOf) && (enum_exists($arrayOf) || class_exists($arrayOf)) ? $arrayOf : null;
    }

    /** @param class-string|null $itemClass For lists: hydrate each item as this enum or input class. */
    private function castValueToType(mixed $val, ?\ReflectionType $type, ?string $itemClass = null): mixed
    {
        if ($itemClass !== null && is_array($val)) {
            return array_map(fn (mixed $item): mixed => $item === null ? null : $this->castToClass($item, $itemClass), $val);
        }
        if ($val === null || !$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $val;
        }

        return $this->castToClass($val, $type->getName());
    }

    /** @param class-string|string $className */
    private function castToClass(mixed $val, string $className): mixed
    {
        if ($val instanceof $className) {
            return $val;
        }

        // Scalars arrive as DateTimeImmutable; a parameter typed DateTime gets a mutable copy
        if ($className === \DateTime::class && $val instanceof \DateTimeImmutable) {
            return \DateTime::createFromImmutable($val);
        }

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

        $loader = $this->dataLoaderFactory !== null
            ? ($this->dataLoaderFactory)($loaderClass)
            : $this->instantiator->create($loaderClass);
        if (!$loader instanceof $loaderClass) {
            throw new LogicException("The DataLoader factory must return an instance of {$loaderClass}.");
        }

        if ($context instanceof Context) {
            $loaders = $context->get('__dataloaders', []);
            $loaders[$loaderClass] = $loader;
            $context->set('__dataloaders', $loaders);
        }

        return $loader;
    }
}

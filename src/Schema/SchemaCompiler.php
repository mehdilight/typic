<?php

declare(strict_types=1);

namespace Typic\Schema;

use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectField;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NullableType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use Typic\Attribute\Arg;
use Typic\Attribute\EnumType as EnumTypeAttr;
use Typic\Attribute\Field as FieldAttr;
use Typic\Attribute\ID;
use Typic\Attribute\InputType as InputTypeAttr;
use Typic\Attribute\Mutation as MutationAttr;
use Typic\Attribute\ObjectType as ObjectTypeAttr;
use Typic\Attribute\Query as QueryAttr;
use Typic\Attribute\ResolveField as ResolveFieldAttr;
use Typic\Attribute\Subscription as SubscriptionAttr;
use Typic\Pagination\Connection;
use Typic\Pagination\PageInfo;
use Typic\Pagination\Paginator;
use Typic\Pagination\PaginatorInfo;
use UnitEnum;

class SchemaCompiler
{
    private TypeRegistry $typeRegistry;
    private ArgumentHydrator $argumentHydrator;

    /**
     * @var array<class-string, object>
     */
    private array $instantiatedResolvers = [];

    /**
     * @var array<class-string, array<string, array{class: class-string, method: ReflectionMethod, attr: ResolveFieldAttr}>>
     */
    private array $externalFieldResolvers = [];

    /**
     * @param array<class-string|object> $resolvers
     * @param array<class-string> $types
     */
    public function __construct(
        private array $resolvers = [],
        private array $types = [],
        private ?ContainerInterface $container = null,
        ?TypeRegistry $typeRegistry = null,
        ?ArgumentHydrator $argumentHydrator = null,
    ) {
        $this->typeRegistry = $typeRegistry ?? new TypeRegistry();
        $this->argumentHydrator = $argumentHydrator ?? new ArgumentHydrator($this->container);
    }

    /**
     * Compiles all registered types and resolvers into an executable GraphQL Schema.
     */
    public function compile(): Schema
    {
        $this->registerPaginationTypes();
        $this->indexResolvers();

        // Register explicitly declared types
        foreach ($this->types as $typeClass) {
            $this->resolveTypeFromClass($typeClass);
        }

        // Build root query fields
        $queryFields = $this->buildRootFields(QueryAttr::class);
        if (empty($queryFields)) {
            // GraphQL requires at least one field on Query type
            $queryFields['_health'] = [
                'type' => Type::string(),
                'resolve' => static fn (): string => 'ok',
            ];
        }

        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => fn (): array => $queryFields,
        ]);

        // Build root mutation fields
        $mutationFields = $this->buildRootFields(MutationAttr::class);
        $mutationType = !empty($mutationFields) ? new ObjectType([
            'name' => 'Mutation',
            'fields' => fn (): array => $mutationFields,
        ]) : null;

        // Build root subscription fields
        $subscriptionFields = $this->buildRootFields(SubscriptionAttr::class);
        $subscriptionType = !empty($subscriptionFields) ? new ObjectType([
            'name' => 'Subscription',
            'fields' => fn (): array => $subscriptionFields,
        ]) : null;

        return new Schema([
            'query' => $queryType,
            'mutation' => $mutationType,
            'subscription' => $subscriptionType,
            'types' => fn (): array => array_values($this->typeRegistry->all()),
        ]);
    }

    private function indexResolvers(): void
    {
        foreach ($this->resolvers as $resolver) {
            $ref = is_object($resolver) ? new ReflectionClass($resolver) : new ReflectionClass($resolver);
            $className = $ref->getName();

            if (is_object($resolver)) {
                $this->instantiatedResolvers[$className] = $resolver;
            }

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $resolveFieldAttrs = $method->getAttributes(ResolveFieldAttr::class);
                foreach ($resolveFieldAttrs as $attr) {
                    /** @var ResolveFieldAttr $instance */
                    $instance = $attr->newInstance();
                    $fieldName = $instance->name ?? $method->getName();
                    $target = $instance->target;

                    $this->externalFieldResolvers[$target][$fieldName] = [
                        'class' => $className,
                        'method' => $method,
                        'attr' => $instance,
                    ];
                }
            }
        }
    }

    /**
     * @param class-string<QueryAttr|MutationAttr|SubscriptionAttr> $attributeClass
     * @return array<string, array<string, mixed>>
     */
    private function buildRootFields(string $attributeClass): array
    {
        $fields = [];

        foreach ($this->resolvers as $resolver) {
            $ref = is_object($resolver) ? new ReflectionClass($resolver) : new ReflectionClass($resolver);
            $resolverClass = $ref->getName();

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $attrs = $method->getAttributes($attributeClass);
                if (empty($attrs)) {
                    continue;
                }

                /** @var QueryAttr|MutationAttr|SubscriptionAttr $attr */
                $attr = $attrs[0]->newInstance();
                $fieldName = $attr->name ?? $method->getName();

                $fieldType = $this->determineReturnType($method, $attr->arrayOf, $attr->type, $attr->nullable);
                $argsConfig = $this->buildArgumentsConfig($method);

                $fields[$fieldName] = [
                    'name' => $fieldName,
                    'type' => $fieldType,
                    'description' => $attr->description,
                    'deprecationReason' => $attr->deprecationReason,
                    'args' => $argsConfig,
                    'resolve' => function ($source, array $args, $context, ResolveInfo $info) use ($resolverClass, $method) {
                        $instance = $this->resolveResolverInstance($resolverClass);
                        $resolvedArgs = $this->argumentHydrator->resolveArguments(
                            $method,
                            $args,
                            $source,
                            $context,
                            $info
                        );
                        return $method->invokeArgs($instance, $resolvedArgs);
                    },
                ];
            }
        }

        return $fields;
    }

    /**
     * @param class-string $className
     */
    public function resolveTypeFromClass(string $className): Type&NamedType
    {
        if ($this->typeRegistry->hasClass($className)) {
            return $this->typeRegistry->getByClass($className);
        }

        assert(class_exists($className) || interface_exists($className) || enum_exists($className));
        $ref = new ReflectionClass($className);

        // Enum check
        if (is_subclass_of($className, UnitEnum::class)) {
            $enumRef = new ReflectionClass($className);
            return $this->compileEnumType($enumRef);
        }

        // InputType check
        if (!empty($ref->getAttributes(InputTypeAttr::class))) {
            return $this->compileInputObjectType($ref);
        }

        // ObjectType (explicit or implicit)
        return $this->compileObjectType($ref);
    }

    /**
     * @param ReflectionClass<UnitEnum> $ref
     */
    private function compileEnumType(ReflectionClass $ref): EnumType
    {
        $attrs = $ref->getAttributes(EnumTypeAttr::class);
        $name = !empty($attrs) && $attrs[0]->newInstance()->name !== null
            ? $attrs[0]->newInstance()->name
            : $ref->getShortName();

        $description = !empty($attrs) ? $attrs[0]->newInstance()->description : null;

        $values = [];
        foreach ($ref->getConstants() as $caseName => $case) {
            $val = $case instanceof \BackedEnum ? $case->value : $caseName;
            $values[$caseName] = [
                'value' => $val,
            ];
        }

        $enumType = new EnumType([
            'name' => $name,
            'description' => $description,
            'values' => $values,
        ]);

        $this->typeRegistry->register($enumType, $ref->getName());

        return $enumType;
    }

    /**
     * @param ReflectionClass<object> $ref
     */
    private function compileInputObjectType(ReflectionClass $ref): InputObjectType
    {
        $attrs = $ref->getAttributes(InputTypeAttr::class);
        $name = !empty($attrs) && $attrs[0]->newInstance()->name !== null
            ? $attrs[0]->newInstance()->name
            : $ref->getShortName();

        $description = !empty($attrs) ? $attrs[0]->newInstance()->description : null;

        $inputObjectType = new InputObjectType([
            'name' => $name,
            'description' => $description,
            'fields' => fn (): array => $this->buildInputFields($ref),
        ]);

        $this->typeRegistry->register($inputObjectType, $ref->getName());

        return $inputObjectType;
    }

    /**
     * @param ReflectionClass<object> $ref
     * @return array<string, array{name: string, type: \GraphQL\Type\Definition\InputType&Type, description: ?string, defaultValue: mixed}>
     */
    private function buildInputFields(ReflectionClass $ref): array
    {
        $fields = [];

        // Check constructor promoted properties
        $constructor = $ref->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $pName = $param->getName();
                $fieldAttr = null;
                $fAttrs = $param->getAttributes(FieldAttr::class);
                if (!empty($fAttrs)) {
                    /** @var FieldAttr $fieldAttr */
                    $fieldAttr = $fAttrs[0]->newInstance();
                    $pName = $fieldAttr->name ?? $pName;
                }

                /** @var \GraphQL\Type\Definition\InputType&Type $fieldType */
                $fieldType = $this->determineInputType($param, $fieldAttr);
                $fields[$pName] = [
                    'name' => $pName,
                    'type' => $fieldType,
                    'description' => $fieldAttr?->description,
                    'defaultValue' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                ];
            }
        }

        // Also check regular properties
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $pName = $prop->getName();
            if (isset($fields[$pName])) {
                continue;
            }

            $fAttrs = $prop->getAttributes(FieldAttr::class);
            $fieldAttr = !empty($fAttrs) ? $fAttrs[0]->newInstance() : null;
            if ($fieldAttr !== null && $fieldAttr->name !== null) {
                $pName = $fieldAttr->name;
            }

            /** @var \GraphQL\Type\Definition\InputType&Type $fieldType */
            $fieldType = $this->determinePropertyInputType($prop, $fieldAttr);
            $fields[$pName] = [
                'name' => $pName,
                'type' => $fieldType,
                'description' => $fieldAttr?->description,
                'defaultValue' => $prop->hasDefaultValue() ? $prop->getDefaultValue() : null,
            ];
        }

        return $fields;
    }

    /**
     * @param ReflectionClass<object> $ref
     */
    private function compileObjectType(ReflectionClass $ref): ObjectType
    {
        $attrs = $ref->getAttributes(ObjectTypeAttr::class);
        $name = !empty($attrs) && $attrs[0]->newInstance()->name !== null
            ? $attrs[0]->newInstance()->name
            : $ref->getShortName();

        $description = !empty($attrs) ? $attrs[0]->newInstance()->description : null;

        $objectType = new ObjectType([
            'name' => $name,
            'description' => $description,
            'fields' => fn (): array => $this->buildObjectFields($ref),
        ]);

        $this->typeRegistry->register($objectType, $ref->getName());

        return $objectType;
    }

    /**
     * @param ReflectionClass<object> $ref
     * @return array<string, array<string, mixed>>
     */
    private function buildObjectFields(ReflectionClass $ref): array
    {
        $fields = [];
        $className = $ref->getName();

        // 1. Class Properties
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $propName = $prop->getName();
            $fieldAttrs = $prop->getAttributes(FieldAttr::class);
            $fieldAttr = !empty($fieldAttrs) ? $fieldAttrs[0]->newInstance() : null;

            $fieldName = $fieldAttr !== null && $fieldAttr->name !== null ? $fieldAttr->name : $propName;
            $isId = !empty($prop->getAttributes(ID::class));

            $type = $this->determinePropertyReturnType($prop, $fieldAttr, $isId);

            $fields[$fieldName] = [
                'name' => $fieldName,
                'type' => $type,
                'description' => $fieldAttr?->description,
                'deprecationReason' => $fieldAttr?->deprecationReason,
                'resolve' => static function ($source) use ($propName) {
                    if (is_object($source) && isset($source->{$propName})) {
                        return $source->{$propName};
                    }
                    if (is_array($source) && array_key_exists($propName, $source)) {
                        return $source[$propName];
                    }
                    return null;
                },
            ];
        }

        // 2. Class Methods with #[Field]
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $fieldAttrs = $method->getAttributes(FieldAttr::class);
            if (empty($fieldAttrs)) {
                continue;
            }

            /** @var FieldAttr $fieldAttr */
            $fieldAttr = $fieldAttrs[0]->newInstance();
            $methodName = $method->getName();
            $fieldName = $fieldAttr->name ?? $methodName;
            $isId = !empty($method->getAttributes(ID::class));

            $type = $this->determineReturnType($method, $fieldAttr->arrayOf, $fieldAttr->type, $fieldAttr->nullable, $isId);
            $argsConfig = $this->buildArgumentsConfig($method);

            $fields[$fieldName] = [
                'name' => $fieldName,
                'type' => $type,
                'description' => $fieldAttr->description,
                'deprecationReason' => $fieldAttr->deprecationReason,
                'args' => $argsConfig,
                'resolve' => function ($source, array $args, $context, ResolveInfo $info) use ($method) {
                    if (!is_object($source)) {
                        return null;
                    }
                    $resolvedArgs = $this->argumentHydrator->resolveArguments($method, $args, $source, $context, $info);
                    return $method->invokeArgs($source, $resolvedArgs);
                },
            ];
        }

        // 3. External #[ResolveField] on target class
        if (isset($this->externalFieldResolvers[$className])) {
            foreach ($this->externalFieldResolvers[$className] as $fieldName => $ext) {
                $extMethod = $ext['method'];
                $extAttr = $ext['attr'];
                $extResolverClass = $ext['class'];

                $type = $this->determineReturnType($extMethod, $extAttr->arrayOf, $extAttr->type, $extAttr->nullable);
                $argsConfig = $this->buildArgumentsConfig($extMethod, $className);

                $fields[$fieldName] = [
                    'name' => $fieldName,
                    'type' => $type,
                    'description' => $extAttr->description,
                    'deprecationReason' => $extAttr->deprecationReason,
                    'args' => $argsConfig,
                    'resolve' => function ($source, array $args, $context, ResolveInfo $info) use ($extResolverClass, $extMethod, $className) {
                        $instance = $this->resolveResolverInstance($extResolverClass);
                        $resolvedArgs = $this->argumentHydrator->resolveArguments(
                            $extMethod,
                            $args,
                            $source,
                            $context,
                            $info,
                            $className
                        );
                        return $extMethod->invokeArgs($instance, $resolvedArgs);
                    },
                ];
            }
        }

        return $fields;
    }

    /**
     * @param ReflectionMethod $method
     * @param class-string|null $skipSourceClass
     * @return array<string, array<string, mixed>>
     */
    private function buildArgumentsConfig(ReflectionMethod $method, ?string $skipSourceClass = null): array
    {
        $argsConfig = [];

        foreach ($method->getParameters() as $param) {
            $pType = $param->getType();
            if ($pType instanceof ReflectionNamedType) {
                $pTypeName = $pType->getName();
                if ($pTypeName === $skipSourceClass ||
                    $pTypeName === 'Typic\Context' ||
                    $pTypeName === ResolveInfo::class ||
                    is_subclass_of($pTypeName, 'Typic\DataLoader\DataLoader') ||
                    ($this->container !== null && $this->container->has($pTypeName))) {
                    continue;
                }
            }

            if (!empty($param->getAttributes('Typic\Attribute\Context')) ||
                !empty($param->getAttributes('Typic\Attribute\Info')) ||
                !empty($param->getAttributes('Typic\Attribute\Inject'))) {
                continue;
            }

            $argName = $param->getName();
            $argAttrs = $param->getAttributes(Arg::class);
            $argAttr = !empty($argAttrs) ? $argAttrs[0]->newInstance() : null;

            if ($argAttr !== null && $argAttr->name !== null) {
                $argName = $argAttr->name;
            }

            $argType = $this->determineArgumentType($param, $argAttr);

            $config = [
                'name' => $argName,
                'type' => $argType,
                'description' => $argAttr?->description,
            ];

            if ($param->isDefaultValueAvailable()) {
                $config['defaultValue'] = $param->getDefaultValue();
            }

            $argsConfig[$argName] = $config;
        }

        return $argsConfig;
    }

    private function nonNull(Type $type): Type
    {
        if ($type instanceof NullableType) {
            return Type::nonNull($type);
        }

        return $type;
    }

    private function determineArgumentType(ReflectionParameter $param, ?Arg $argAttr): Type
    {
        $isId = !empty($param->getAttributes(ID::class));
        $type = $param->getType();
        $nullable = ($argAttr !== null && $argAttr->nullable !== null)
            ? $argAttr->nullable
            : ($param->isDefaultValueAvailable() || ($type !== null && $type->allowsNull()));

        if ($isId) {
            $base = Type::id();
            return $nullable ? $base : $this->nonNull($base);
        }

        if ($argAttr !== null && $argAttr->arrayOf !== null) {
            $itemType = $this->resolveTypeByNameOrClass($argAttr->arrayOf);
            $listType = Type::listOf($this->nonNull($itemType));
            return $nullable ? $listType : $this->nonNull($listType);
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $base = match ($name) {
                'int' => Type::int(),
                'float' => Type::float(),
                'string' => Type::string(),
                'bool' => Type::boolean(),
                default => $this->resolveTypeByNameOrClass($name),
            };

            return $nullable ? $base : $this->nonNull($base);
        }

        return Type::string();
    }

    private function determineInputType(ReflectionParameter $param, ?FieldAttr $fieldAttr): Type
    {
        $isId = !empty($param->getAttributes(ID::class));
        $type = $param->getType();
        $nullable = ($fieldAttr !== null && $fieldAttr->nullable !== null)
            ? $fieldAttr->nullable
            : ($param->isDefaultValueAvailable() || ($type !== null && $type->allowsNull()));

        if ($isId) {
            $base = Type::id();
            return $nullable ? $base : $this->nonNull($base);
        }

        if ($fieldAttr !== null && $fieldAttr->arrayOf !== null) {
            $itemType = $this->resolveTypeByNameOrClass($fieldAttr->arrayOf);
            $listType = Type::listOf($this->nonNull($itemType));
            return $nullable ? $listType : $this->nonNull($listType);
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $base = match ($name) {
                'int' => Type::int(),
                'float' => Type::float(),
                'string' => Type::string(),
                'bool' => Type::boolean(),
                default => $this->resolveTypeByNameOrClass($name),
            };

            return $nullable ? $base : $this->nonNull($base);
        }

        return Type::string();
    }

    private function determinePropertyInputType(ReflectionProperty $prop, ?FieldAttr $fieldAttr): Type
    {
        $isId = !empty($prop->getAttributes(ID::class));
        $type = $prop->getType();
        $nullable = ($fieldAttr !== null && $fieldAttr->nullable !== null)
            ? $fieldAttr->nullable
            : ($prop->hasDefaultValue() || ($type !== null && $type->allowsNull()));

        if ($isId) {
            $base = Type::id();
            return $nullable ? $base : $this->nonNull($base);
        }

        if ($fieldAttr !== null && $fieldAttr->arrayOf !== null) {
            $itemType = $this->resolveTypeByNameOrClass($fieldAttr->arrayOf);
            $listType = Type::listOf($this->nonNull($itemType));
            return $nullable ? $listType : $this->nonNull($listType);
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $base = match ($name) {
                'int' => Type::int(),
                'float' => Type::float(),
                'string' => Type::string(),
                'bool' => Type::boolean(),
                default => $this->resolveTypeByNameOrClass($name),
            };

            return $nullable ? $base : $this->nonNull($base);
        }

        return Type::string();
    }

    private function determinePropertyReturnType(ReflectionProperty $prop, ?FieldAttr $fieldAttr, bool $isId = false): Type
    {
        $type = $prop->getType();
        $nullable = ($fieldAttr !== null && $fieldAttr->nullable !== null)
            ? $fieldAttr->nullable
            : ($type === null || $type->allowsNull());

        if ($isId) {
            $base = Type::id();
            return $nullable ? $base : $this->nonNull($base);
        }

        if ($fieldAttr !== null && $fieldAttr->arrayOf !== null) {
            $itemType = $this->resolveTypeByNameOrClass($fieldAttr->arrayOf);
            $listType = Type::listOf($this->nonNull($itemType));
            return $nullable ? $listType : $this->nonNull($listType);
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $base = match ($name) {
                'int' => Type::int(),
                'float' => Type::float(),
                'string' => Type::string(),
                'bool' => Type::boolean(),
                default => $this->resolveTypeByNameOrClass($name),
            };

            return $nullable ? $base : $this->nonNull($base);
        }

        return Type::string();
    }

    private function determineReturnType(
        ReflectionMethod $method,
        ?string $arrayOf = null,
        ?string $explicitType = null,
        ?bool $explicitNullable = null,
        bool $isId = false,
    ): Type {
        $type = $method->getReturnType();
        $nullable = $explicitNullable ?? ($type === null || $type->allowsNull());

        if ($isId) {
            $base = Type::id();
            return $nullable ? $base : $this->nonNull($base);
        }

        // Explicit type override string
        if ($explicitType !== null) {
            return $this->resolveTypeByNameOrClass($explicitType);
        }

        // List arrayOf
        if ($arrayOf !== null) {
            // Check if return type is Paginator or Connection
            if ($type instanceof ReflectionNamedType) {
                $returnName = $type->getName();
                if ($returnName === Paginator::class || is_subclass_of($returnName, Paginator::class)) {
                    $paginatorType = $this->resolvePaginatorType($arrayOf);
                    return $nullable ? $paginatorType : $this->nonNull($paginatorType);
                }
                if ($returnName === Connection::class || is_subclass_of($returnName, Connection::class)) {
                    $connectionType = $this->resolveConnectionType($arrayOf);
                    return $nullable ? $connectionType : $this->nonNull($connectionType);
                }
            }

            $itemType = $this->resolveTypeByNameOrClass($arrayOf);
            $list = Type::listOf($this->nonNull($itemType));
            return $nullable ? $list : $this->nonNull($list);
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            // Paginator without explicit arrayOf
            if ($name === Paginator::class) {
                // Check docblock @return Paginator<Post>
                $itemClass = $this->parseGenericFromDocblock($method);
                if ($itemClass !== null) {
                    $paginatorType = $this->resolvePaginatorType($itemClass);
                    return $nullable ? $paginatorType : $this->nonNull($paginatorType);
                }
            }

            if ($name === Connection::class) {
                $itemClass = $this->parseGenericFromDocblock($method);
                if ($itemClass !== null) {
                    $connectionType = $this->resolveConnectionType($itemClass);
                    return $nullable ? $connectionType : $this->nonNull($connectionType);
                }
            }

            if ($name === 'GraphQL\Deferred' || $name === 'GraphQL\Executor\Promise\Promise') {
                $docType = $this->parseReturnTypeFromDocblock($method);
                if ($docType !== null) {
                    $base = $this->resolveTypeByNameOrClass($docType);
                    return $nullable ? $base : $this->nonNull($base);
                }
            }

            $base = match ($name) {
                'int' => Type::int(),
                'float' => Type::float(),
                'string' => Type::string(),
                'bool' => Type::boolean(),
                'void' => Type::boolean(),
                default => $this->resolveTypeByNameOrClass($name),
            };

            return $nullable ? $base : $this->nonNull($base);
        }

        return Type::string();
    }

    private function resolveTypeByNameOrClass(string $nameOrClass): Type&NamedType
    {
        if ($this->typeRegistry->has($nameOrClass)) {
            return $this->typeRegistry->get($nameOrClass);
        }

        if (class_exists($nameOrClass) || interface_exists($nameOrClass)) {
            return $this->resolveTypeFromClass($nameOrClass);
        }

        return match (strtolower($nameOrClass)) {
            'int' => Type::int(),
            'float' => Type::float(),
            'string' => Type::string(),
            'bool', 'boolean' => Type::boolean(),
            'id' => Type::id(),
            default => throw new InvalidArgumentException("Unknown GraphQL type '$nameOrClass'"),
        };
    }

    private function resolvePaginatorType(string $itemClass): ObjectType
    {
        $itemType = $this->resolveTypeByNameOrClass($itemClass);
        $name = $itemType->name() . 'Paginator';

        if ($this->typeRegistry->has($name)) {
            /** @var ObjectType */
            return $this->typeRegistry->get($name);
        }

        $paginatorInfoType = $this->typeRegistry->get('PaginatorInfo');

        $paginatorType = new ObjectType([
            'name' => $name,
            'description' => "A paginated list of {$itemType->name()} items.",
            'fields' => [
                'data' => [
                    'type' => $this->nonNull(Type::listOf($this->nonNull($itemType))),
                    'description' => "List of {$itemType->name()} items.",
                ],
                'paginatorInfo' => [
                    'type' => $this->nonNull($paginatorInfoType),
                    'description' => 'Pagination metadata.',
                ],
            ],
        ]);

        $this->typeRegistry->register($paginatorType);

        return $paginatorType;
    }

    private function resolveConnectionType(string $itemClass): ObjectType
    {
        $itemType = $this->resolveTypeByNameOrClass($itemClass);
        $connName = $itemType->name() . 'Connection';
        $edgeName = $itemType->name() . 'Edge';

        if ($this->typeRegistry->has($connName)) {
            /** @var ObjectType */
            return $this->typeRegistry->get($connName);
        }

        $edgeType = new ObjectType([
            'name' => $edgeName,
            'description' => "An edge in a {$itemType->name()} connection.",
            'fields' => [
                'node' => [
                    'type' => $this->nonNull($itemType),
                    'description' => 'The item at the end of the edge.',
                ],
                'cursor' => [
                    'type' => $this->nonNull(Type::string()),
                    'description' => 'A cursor for use in pagination.',
                ],
            ],
        ]);
        $this->typeRegistry->register($edgeType);

        $pageInfoType = $this->typeRegistry->get('PageInfo');

        $connectionType = new ObjectType([
            'name' => $connName,
            'description' => "A connection to a list of {$itemType->name()} items.",
            'fields' => [
                'edges' => [
                    'type' => $this->nonNull(Type::listOf($this->nonNull($edgeType))),
                    'description' => "A list of {$itemType->name()} edges.",
                ],
                'pageInfo' => [
                    'type' => $this->nonNull($pageInfoType),
                    'description' => 'Information to aid in pagination.',
                ],
                'totalCount' => [
                    'type' => Type::int(),
                    'description' => 'Identifies the total count of items in the connection.',
                ],
            ],
        ]);

        $this->typeRegistry->register($connectionType);

        return $connectionType;
    }

    private function registerPaginationTypes(): void
    {
        if (!$this->typeRegistry->has('PaginatorInfo')) {
            $paginatorInfo = new ObjectType([
                'name' => 'PaginatorInfo',
                'description' => 'Pagination information for a paginated list.',
                'fields' => [
                    'hasMorePages' => ['type' => Type::nonNull(Type::boolean())],
                    'currentPage' => ['type' => Type::nonNull(Type::int())],
                    'lastPage' => ['type' => Type::nonNull(Type::int())],
                    'perPage' => ['type' => Type::nonNull(Type::int())],
                    'total' => ['type' => Type::nonNull(Type::int())],
                    'firstItem' => ['type' => Type::int()],
                    'lastItem' => ['type' => Type::int()],
                ],
            ]);
            $this->typeRegistry->register($paginatorInfo, PaginatorInfo::class);
        }

        if (!$this->typeRegistry->has('PageInfo')) {
            $pageInfo = new ObjectType([
                'name' => 'PageInfo',
                'description' => 'Pagination information for Relay connection pagination.',
                'fields' => [
                    'hasNextPage' => ['type' => Type::nonNull(Type::boolean())],
                    'hasPreviousPage' => ['type' => Type::nonNull(Type::boolean())],
                    'startCursor' => ['type' => Type::string()],
                    'endCursor' => ['type' => Type::string()],
                ],
            ]);
            $this->typeRegistry->register($pageInfo, PageInfo::class);
        }
    }

    private function parseGenericFromDocblock(ReflectionMethod $method): ?string
    {
        $doc = $method->getDocComment();
        if ($doc === false) {
            return null;
        }

        if (preg_match('/@return\s+(?:LengthAwarePaginator|Paginator|Connection)<([a-zA-Z0-9_\\\\]+)>/', $doc, $matches)) {
            $class = $matches[1];
            if (class_exists($class)) {
                return $class;
            }
            // Check in method namespace
            $ns = $method->getDeclaringClass()->getNamespaceName();
            $fqcn = $ns . '\\' . $class;
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        return null;
    }

    private function parseReturnTypeFromDocblock(ReflectionMethod $method): ?string
    {
        $doc = $method->getDocComment();
        if ($doc === false) {
            return null;
        }

        if (preg_match('/@return\s+(?:Deferred|Promise)<([a-zA-Z0-9_\\\\]+)>/', $doc, $matches)) {
            return $this->resolveClassFromDocblock($matches[1], $method);
        }

        if (preg_match('/@return\s+([^\s]+)/', $doc, $matches)) {
            $parts = explode('|', $matches[1]);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === 'Deferred' || $part === 'Promise' || $part === 'null' || str_contains($part, 'Deferred') || str_contains($part, 'Promise')) {
                    continue;
                }
                return $this->resolveClassFromDocblock($part, $method);
            }
        }

        return null;
    }

    private function resolveClassFromDocblock(string $className, ReflectionMethod $method): string
    {
        if (class_exists($className) || interface_exists($className) || enum_exists($className) || $this->typeRegistry->has($className)) {
            return $className;
        }

        $ns = $method->getDeclaringClass()->getNamespaceName();
        $fqcn = $ns . '\\' . $className;
        if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) {
            return $fqcn;
        }

        return $className;
    }

    /**
     * @param class-string $className
     */
    private function resolveResolverInstance(string $className): object
    {
        if (isset($this->instantiatedResolvers[$className])) {
            return $this->instantiatedResolvers[$className];
        }

        if ($this->container !== null && $this->container->has($className)) {
            $instance = $this->container->get($className);
            $this->instantiatedResolvers[$className] = $instance;
            return $instance;
        }

        $ref = new ReflectionClass($className);
        $constructor = $ref->getConstructor();
        if ($constructor === null || empty($constructor->getParameters())) {
            $instance = new $className();
            $this->instantiatedResolvers[$className] = $instance;
            return $instance;
        }

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

        $instance = $ref->newInstanceArgs($args);
        $this->instantiatedResolvers[$className] = $instance;

        return $instance;
    }
}

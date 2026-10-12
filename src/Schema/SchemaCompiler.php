<?php

declare(strict_types=1);

namespace Typic\Schema;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectField;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NullableType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use Typic\Attribute\Arg;
use Typic\Attribute\Deprecated;
use Typic\Attribute\Description;
use Typic\Attribute\EnumType as EnumTypeAttr;
use Typic\Attribute\FromCall;
use Typic\Attribute\Field as FieldAttr;
use Typic\Attribute\ID;
use Typic\Attribute\InputType as InputTypeAttr;
use Typic\Attribute\InterfaceType as InterfaceTypeAttr;
use Typic\Attribute\Mutation as MutationAttr;
use Typic\Attribute\ObjectType as ObjectTypeAttr;
use Typic\Attribute\Payload as PayloadAttr;
use Typic\Attribute\Query as QueryAttr;
use Typic\Attribute\ResolveField as ResolveFieldAttr;
use Typic\Attribute\Scalar as ScalarAttr;
use Typic\Attribute\Subscription as SubscriptionAttr;
use Typic\Attribute\UnionType as UnionTypeAttr;
use Typic\Context;
use Typic\DataLoader\DataLoader;
use Typic\DataLoader\FiberResolver;
use Typic\Middleware\MiddlewareStack;
use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;
use Typic\Pagination\Connection;
use Typic\Pagination\PageInfo;
use Typic\Pagination\Paginator;
use Typic\Pagination\PaginatorInfo;
use Typic\Scalar\DateTimeScalar;
use Typic\Scalar\ScalarType;
use Typic\Scalar\ScalarValue;
use Typic\Scalar\ValueObjectScalar;
use Typic\Subscription\SubscriptionSignal;
use Typic\Validation\Validator;
use Throwable;
use UnitEnum;

class SchemaCompiler
{
    private TypeRegistry $typeRegistry;
    private ArgumentHydrator $argumentHydrator;
    private MiddlewareStack $middleware;
    private Instantiator $instantiator;
    private Validator $validator;

    /**
     * @var array<class-string, object>
     */
    private array $instantiatedResolvers = [];

    /**
     * @var array<class-string, array<string, array{class: class-string, method: ReflectionMethod, attr: ResolveFieldAttr}>>
     */
    private array $externalFieldResolvers = [];

    /** @var array<string, bool> Whether each resolver method takes a DataLoader parameter */
    private array $usesDataLoader = [];

    /** @var array<class-string, ScalarType> PHP type => scalar */
    private array $scalars;

    /**
     * @param array<class-string|object> $resolvers
     * @param array<class-string> $types
     * @param (callable(class-string<DataLoader<mixed, mixed>>): DataLoader<mixed, mixed>)|null $dataLoaderFactory
     * @param array<class-string, ScalarType> $scalars Custom scalars by PHP type, on top of DateTime
     */
    public function __construct(
        private array $resolvers = [],
        private array $types = [],
        private ?ContainerInterface $container = null,
        ?TypeRegistry $typeRegistry = null,
        ?ArgumentHydrator $argumentHydrator = null,
        ?MiddlewareStack $middleware = null,
        array $scalars = [],
        private mixed $dataLoaderFactory = null,
    ) {
        $this->scalars = $scalars + [
            \DateTimeInterface::class => new DateTimeScalar(),
            \Psr\Http\Message\UploadedFileInterface::class => new \Typic\Scalar\UploadScalar(),
        ];
        $this->typeRegistry = $typeRegistry ?? new TypeRegistry();
        $this->argumentHydrator = $argumentHydrator ?? new ArgumentHydrator($this->container, $this->dataLoaderFactory);
        $this->middleware = $middleware ?? new MiddlewareStack(container: $this->container);
        $this->instantiator = new Instantiator($this->container);
        $this->validator = new Validator();
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
            // Types load by name as queries need them, so a query only builds the fields it touches
            'typeLoader' => fn (string $name): (Type&NamedType)|null => $this->loadType($name),
            'types' => fn (): array => array_values($this->allTypes()),
            // Typic never replaces Int, String, etc.; saying so keeps graphql-php from loading every type to check
            'scalarOverrides' => [],
        ]);
    }

    private function loadType(string $name): (Type&NamedType)|null
    {
        if (!$this->typeRegistry->has($name)) {
            // Not reached yet: build every type's fields, which compiles everything they reference
            $this->allTypes();
        }

        return $this->typeRegistry->has($name) ? $this->typeRegistry->get($name) : null;
    }

    /**
     * Every type, after building fields until no new type appears.
     *
     * @return array<string, Type&NamedType>
     */
    private function allTypes(): array
    {
        $built = [];
        do {
            $before = count($this->typeRegistry->all());
            foreach ($this->typeRegistry->all() as $name => $type) {
                if (isset($built[$name])) {
                    continue;
                }
                $built[$name] = true;
                if ($type instanceof ObjectType || $type instanceof InterfaceType || $type instanceof InputObjectType) {
                    $type->getFields();
                }
                if ($type instanceof UnionType) {
                    $type->getTypes();
                }
                if ($type instanceof ObjectType || $type instanceof InterfaceType) {
                    $type->getInterfaces();
                }
            }
        } while (count($this->typeRegistry->all()) > $before);

        return $this->typeRegistry->all();
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

                    $this->checkResolver($ref, $method);
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
        $isSubscription = $attributeClass === SubscriptionAttr::class;

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
                $stack = $this->middleware->for($ref, $method);
                $this->checkResolver($ref, $method);

                $fields[$fieldName] = [
                    'name' => $fieldName,
                    'type' => $fieldType,
                    'description' => $this->extractDescription($method, $attr->description),
                    'deprecationReason' => $this->extractDeprecationReason($method, $attr->deprecationReason),
                    'args' => $argsConfig,
                    'resolve' => function ($source, array $args, $context, ResolveInfo $info) use ($resolverClass, $method, $stack, $isSubscription) {
                        if ($isSubscription && $context instanceof Context && $context->get('subscribing') === true) {
                            // Subscribing: run the middleware only, so it can refuse the subscription
                            MiddlewareStack::run($stack, new ResolverCall($source, $args, $context, $info), static fn (): mixed => null);
                            throw SubscriptionSignal::accepted();
                        }

                        $instance = $this->resolveResolverInstance($resolverClass);
                        $result = $this->callResolver($stack, $method, $instance, $source, $args, $context, $info);
                        if ($isSubscription && $result === false) {
                            throw SubscriptionSignal::filtered();
                        }
                        if ($result === true && $source !== null) {
                            return $source;
                        }
                        return $result;
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

        $scalar = $this->scalarFor($ref);
        if ($scalar !== null) {
            return $this->compileScalar($scalar, $className);
        }

        // Enum check
        if (is_subclass_of($className, UnitEnum::class)) {
            $enumRef = new ReflectionClass($className);
            return $this->compileEnumType($enumRef);
        }

        // InputType check
        if (!empty($ref->getAttributes(InputTypeAttr::class))) {
            return $this->compileInputObjectType($ref);
        }

        if (!empty($ref->getAttributes(UnionTypeAttr::class))) {
            return $this->compileUnionType($ref);
        }

        if (!empty($ref->getAttributes(InterfaceTypeAttr::class))) {
            return $this->compileInterfaceType($ref);
        }

        // Check method loader dependencies without forcing lazy GraphQL fields to compile.
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($this->fieldDeclaration($ref, $method) !== null) {
                $this->checkLoaderParameters($method);
            }
        }

        // ObjectType (explicit or implicit)
        return $this->compileObjectType($ref);
    }

    /**
     * @param ReflectionClass<object> $ref
     */
    private function scalarFor(ReflectionClass $ref): ?ScalarType
    {
        foreach ($this->scalars as $phpType => $scalar) {
            if (is_a($ref->getName(), $phpType, true)) {
                return $scalar;
            }
        }

        $attrs = $ref->getAttributes(ScalarAttr::class);
        if ($attrs === []) {
            return null;
        }
        if (!$ref->implementsInterface(ScalarValue::class)) {
            throw new InvalidArgumentException(sprintf('%s has #[Scalar] but does not implement %s.', $ref->getName(), ScalarValue::class));
        }

        $attr = $attrs[0]->newInstance();
        /** @var class-string<ScalarValue> $class */
        $class = $ref->getName();

        return new ValueObjectScalar($class, $attr->name ?? $ref->getShortName(), $attr->description);
    }

    /**
     * @param class-string $className
     */
    private function compileScalar(ScalarType $scalar, string $className): CustomScalarType
    {
        if ($this->typeRegistry->has($scalar->name())) {
            $existing = $this->typeRegistry->get($scalar->name());
            if ($existing instanceof CustomScalarType) {
                $this->typeRegistry->mapClass($className, $scalar->name());
                return $existing;
            }
        }

        // Errors from parse() become GraphQL errors, which show their message to the client
        $parse = static function (mixed $value) use ($scalar): mixed {
            try {
                return $scalar->parse($value);
            } catch (Error $e) {
                throw $e;
            } catch (Throwable $e) {
                // Without the original exception as "previous", so graphql-php treats the message as safe to show
                throw new Error($e->getMessage());
            }
        };

        $type = new CustomScalarType([
            'name' => $scalar->name(),
            'description' => $scalar->description(),
            'serialize' => static fn (mixed $value): mixed => $scalar->serialize($value),
            'parseValue' => $parse,
            'parseLiteral' => static fn (Node $node, ?array $variables = null): mixed => $parse(AST::valueFromASTUntyped($node, $variables)),
        ]);
        $this->typeRegistry->register($type, $className);

        return $type;
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

        $description = $this->extractDescription($ref, !empty($attrs) ? $attrs[0]->newInstance()->description : null);

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

        $description = $this->extractDescription($ref, !empty($attrs) ? $attrs[0]->newInstance()->description : null);

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
     * @return array<string, array{name: string, type: \GraphQL\Type\Definition\InputType&Type, description: ?string, defaultValue?: mixed}>
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
                $config = [
                    'name' => $pName,
                    'type' => $fieldType,
                    'description' => $this->extractDescription($param, $fieldAttr?->description),
                ];
                if ($param->isDefaultValueAvailable()) {
                    $config['defaultValue'] = $param->getDefaultValue();
                }
                $fields[$pName] = $config;
            }
        }

        // Also check regular properties (promoted ones were handled with the constructor)
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $pName = $prop->getName();
            if ($prop->isPromoted() || isset($fields[$pName])) {
                continue;
            }

            $fAttrs = $prop->getAttributes(FieldAttr::class);
            $fieldAttr = !empty($fAttrs) ? $fAttrs[0]->newInstance() : null;
            if ($fieldAttr !== null && $fieldAttr->name !== null) {
                $pName = $fieldAttr->name;
            }

            /** @var \GraphQL\Type\Definition\InputType&Type $fieldType */
            $fieldType = $this->determinePropertyInputType($prop, $fieldAttr);
            $config = [
                'name' => $pName,
                'type' => $fieldType,
                'description' => $this->extractDescription($prop, $fieldAttr?->description),
            ];
            if ($prop->hasDefaultValue()) {
                $config['defaultValue'] = $prop->getDefaultValue();
            }
            $fields[$pName] = $config;
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

        $description = $this->extractDescription($ref, !empty($attrs) ? $attrs[0]->newInstance()->description : null);

        $objectType = new ObjectType([
            'name' => $name,
            'description' => $description,
            'fields' => fn (): array => $this->buildObjectFields($ref),
            'interfaces' => fn (): array => $this->interfacesOf($ref),
        ]);

        $this->typeRegistry->register($objectType, $ref->getName());

        return $objectType;
    }

    /**
     * An interface from a PHP interface or abstract class with #[InterfaceType]. Its fields are
     * its #[Field] methods and public properties; object types inherit them.
     *
     * @param ReflectionClass<object> $ref
     */
    private function compileInterfaceType(ReflectionClass $ref): InterfaceType
    {
        $attr = $ref->getAttributes(InterfaceTypeAttr::class)[0]->newInstance();

        $interfaceType = new InterfaceType([
            'name' => $attr->name ?? $ref->getShortName(),
            'description' => $this->extractDescription($ref, $attr->description),
            'fields' => fn (): array => $this->buildObjectFields($ref),
            'interfaces' => fn (): array => $this->interfacesOf($ref),
            'resolveType' => fn (mixed $value): ObjectType => $this->objectTypeOf($value),
        ]);

        $this->typeRegistry->register($interfaceType, $ref->getName());

        return $interfaceType;
    }

    /**
     * A union from a class or interface with #[UnionType(types: [...])].
     *
     * @param ReflectionClass<object> $ref
     */
    private function compileUnionType(ReflectionClass $ref): UnionType
    {
        $attr = $ref->getAttributes(UnionTypeAttr::class)[0]->newInstance();

        $unionType = new UnionType([
            'name' => $attr->name ?? $ref->getShortName(),
            'description' => $this->extractDescription($ref, $attr->description),
            'types' => fn (): array => array_map(
                function (string $member) use ($ref): ObjectType {
                    $type = $this->resolveTypeFromClass($member);
                    if (!$type instanceof ObjectType) {
                        throw new InvalidArgumentException(sprintf('Union %s lists %s, which is not an object type.', $ref->getName(), $member));
                    }
                    return $type;
                },
                $attr->types,
            ),
            'resolveType' => fn (mixed $value): ObjectType => $this->objectTypeOf($value),
        ]);

        $this->typeRegistry->register($unionType, $ref->getName());

        return $unionType;
    }

    /**
     * The GraphQL interfaces a class implements: every ancestor with #[InterfaceType].
     *
     * @param ReflectionClass<object> $ref
     * @return list<InterfaceType>
     */
    private function interfacesOf(ReflectionClass $ref): array
    {
        $interfaces = [];
        foreach ($this->ancestors($ref) as $ancestor) {
            if ($ancestor->getAttributes(InterfaceTypeAttr::class) !== []) {
                $type = $this->resolveTypeFromClass($ancestor->getName());
                if ($type instanceof InterfaceType) {
                    $interfaces[] = $type;
                }
            }
        }

        return $interfaces;
    }

    /**
     * Parent classes and interfaces, nearest first.
     *
     * @param ReflectionClass<object> $ref
     * @return list<ReflectionClass<object>>
     */
    private function ancestors(ReflectionClass $ref): array
    {
        $ancestors = [];
        for ($parent = $ref->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            $ancestors[] = $parent;
        }

        return [...$ancestors, ...array_values($ref->getInterfaces())];
    }

    /**
     * The object type of a value returned for an interface or union field.
     */
    private function objectTypeOf(mixed $value): ObjectType
    {
        $class = is_object($value) ? $value::class : get_debug_type($value);
        $type = is_object($value) && $this->typeRegistry->hasClass($value::class)
            ? $this->typeRegistry->getByClass($value::class)
            : null;

        if (!$type instanceof ObjectType) {
            throw new InvalidArgumentException("{$class} is not a GraphQL type. Add #[ObjectType] to it and register or scan it.");
        }

        return $type;
    }

    /**
     * The method that carries a method's #[Field]: the method itself, or the method it
     * implements or overrides, so implementations need not repeat the attribute.
     *
     * @param ReflectionClass<object> $ref
     */
    private function fieldDeclaration(ReflectionClass $ref, ReflectionMethod $method): ?ReflectionMethod
    {
        if ($method->getAttributes(FieldAttr::class) !== []) {
            return $method;
        }

        foreach ($this->ancestors($ref) as $ancestor) {
            if ($ancestor->hasMethod($method->getName())) {
                $declared = $ancestor->getMethod($method->getName());
                if ($declared->getAttributes(FieldAttr::class) !== []) {
                    return $declared;
                }
            }
        }

        return null;
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
                'description' => $this->extractDescription($prop, $fieldAttr?->description),
                'deprecationReason' => $this->extractDeprecationReason($prop, $fieldAttr?->deprecationReason),
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

        // 2. Class Methods with #[Field], declared here or on an interface or parent class
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $declaration = $this->fieldDeclaration($ref, $method);
            if ($declaration === null) {
                continue;
            }

            $fieldAttr = $declaration->getAttributes(FieldAttr::class)[0]->newInstance();
            $methodName = $method->getName();
            $fieldName = $fieldAttr->name ?? $methodName;
            $isId = !empty($declaration->getAttributes(ID::class));

            $type = $this->determineReturnType($method, $fieldAttr->arrayOf, $fieldAttr->type, $fieldAttr->nullable, $isId);
            $argsConfig = $this->buildArgumentsConfig($method);
            $stack = $this->middleware->for($ref, $method);

            $fields[$fieldName] = [
                'name' => $fieldName,
                'type' => $type,
                'description' => $this->extractDescription($declaration, $fieldAttr->description),
                'deprecationReason' => $this->extractDeprecationReason($declaration, $fieldAttr->deprecationReason),
                'args' => $argsConfig,
                'resolve' => function ($source, array $args, $context, ResolveInfo $info) use ($method, $stack) {
                    if (!is_object($source)) {
                        return null;
                    }
                    return $this->callResolver($stack, $method, $source, $source, $args, $context, $info);
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
                $stack = $this->middleware->for(new ReflectionClass($extResolverClass), $extMethod);

                $fields[$fieldName] = [
                    'name' => $fieldName,
                    'type' => $type,
                    'description' => $this->extractDescription($extMethod, $extAttr->description),
                    'deprecationReason' => $this->extractDeprecationReason($extMethod, $extAttr->deprecationReason),
                    'args' => $argsConfig,
                    'resolve' => function ($source, array $args, $context, ResolveInfo $info) use ($extResolverClass, $extMethod, $className, $stack) {
                        $instance = $this->resolveResolverInstance($extResolverClass);
                        return $this->callResolver($stack, $extMethod, $instance, $source, $args, $context, $info, $className);
                    },
                ];
            }
        }

        return $fields;
    }

    /**
     * Call a resolver method inside its middleware stack.
     *
     * Resolvers that take a DataLoader run in a Fiber, so DataLoader::get() can pause them
     * until the batch loads. The middleware runs in the same Fiber, so $next() returns the
     * loaded value rather than a promise.
     *
     * @param list<ResolverMiddleware> $stack
     * @param array<string, mixed> $args
     * @param class-string|null $sourceClass
     */
    private function callResolver(
        array $stack,
        ReflectionMethod $method,
        object $target,
        mixed $source,
        array $args,
        mixed $context,
        ResolveInfo $info,
        ?string $sourceClass = null,
    ): mixed {
        $resolve = function (?ResolverCall $call) use ($method, $target, $source, $args, $context, $info, $sourceClass): mixed {
            $args = $call?->args() ?? $args;
            $this->validator->validate($method, $args);

            $resolvedArgs = $this->argumentHydrator->resolveArguments(
                $method,
                $args,
                $source,
                $context,
                $info,
                $sourceClass,
                $call,
            );

            return $method->invokeArgs($target, $resolvedArgs);
        };

        $run = $stack === []
            ? static fn (): mixed => $resolve(null)
            : static fn (): mixed => MiddlewareStack::run($stack, new ResolverCall($source, $args, $context, $info), $resolve);

        $id = $method->getDeclaringClass()->getName() . '::' . $method->getName();
        $this->usesDataLoader[$id] ??= $this->hasDataLoaderParameter($method);

        return $this->usesDataLoader[$id] ? FiberResolver::run($run) : $run();
    }

    private function hasDataLoaderParameter(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && is_a($type->getName(), DataLoader::class, true)) {
                return true;
            }
        }

        return false;
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
                    $pTypeName === 'Typic\Subscription\PubSub\PubSubInterface' ||
                    is_subclass_of($pTypeName, 'Typic\Subscription\PubSub\PubSubInterface') ||
                    is_subclass_of($pTypeName, 'Typic\DataLoader\DataLoader') ||
                    ($this->container !== null && !$this->isInputClass($pTypeName) && $param->getAttributes(Arg::class) === [] && $this->container->has($pTypeName))) {
                    continue;
                }
            }

            if (!empty($param->getAttributes('Typic\Attribute\Context')) ||
                !empty($param->getAttributes(FromCall::class)) ||
                !empty($param->getAttributes(\Typic\Attribute\FromRequest::class)) ||
                !empty($param->getAttributes('Typic\Attribute\Info')) ||
                !empty($param->getAttributes('Typic\Attribute\Inject')) ||
                !empty($param->getAttributes(PayloadAttr::class)) ||
                $param->getName() === 'payload' ||
                $param->getName() === 'event') {
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
                'description' => $this->extractDescription($param, $argAttr?->description),
            ];

            if ($param->isDefaultValueAvailable()) {
                $config['defaultValue'] = $param->getDefaultValue();
            }

            $argsConfig[$argName] = $config;
        }

        return $argsConfig;
    }

    private function isInputClass(string $class): bool
    {
        if (!class_exists($class) && !interface_exists($class)) {
            return false;
        }
        $ref = new ReflectionClass($class);
        return $ref->getAttributes(InputTypeAttr::class) !== []
            || is_a($class, UnitEnum::class, true)
            || $this->scalarFor($ref) !== null;
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
            if ($name === Paginator::class || is_subclass_of($name, Paginator::class)) {
                throw new InvalidArgumentException(sprintf(
                    "Method '%s::%s' returns '%s', which requires explicit 'arrayOf' on the #[Query], #[Field], or #[ResolveField] attribute.",
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $name
                ));
            }

            if ($name === Connection::class || is_subclass_of($name, Connection::class)) {
                throw new InvalidArgumentException(sprintf(
                    "Method '%s::%s' returns '%s', which requires explicit 'arrayOf' on the #[Query], #[Field], or #[ResolveField] attribute.",
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $name
                ));
            }

            if ($name === 'GraphQL\Deferred' || $name === 'GraphQL\Executor\Promise\Promise' || is_subclass_of($name, 'GraphQL\Deferred') || is_subclass_of($name, 'GraphQL\Executor\Promise\Promise')) {
                throw new InvalidArgumentException(sprintf(
                    "Method '%s::%s' returns '%s', which requires explicit 'type' or 'arrayOf' on the #[Query], #[Field], or #[ResolveField] attribute.",
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $name
                ));
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

    /**
     * @param ReflectionClass<*>|ReflectionMethod|ReflectionProperty|ReflectionParameter $reflector
     */
    private function extractDescription(
        ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionParameter $reflector,
        ?string $explicitDescription = null
    ): ?string {
        if ($explicitDescription !== null && $explicitDescription !== '') {
            return $explicitDescription;
        }

        $descAttrs = $reflector->getAttributes(Description::class);
        if (!empty($descAttrs)) {
            return $descAttrs[0]->newInstance()->text;
        }

        return null;
    }

    private function extractDeprecationReason(
        ReflectionMethod|ReflectionProperty $reflector,
        ?string $explicitReason = null
    ): ?string {
        if ($explicitReason !== null && $explicitReason !== '') {
            return $explicitReason;
        }

        $depAttrs = $reflector->getAttributes(Deprecated::class);
        if (!empty($depAttrs)) {
            return $depAttrs[0]->newInstance()->reason;
        }

        if (class_exists(\Deprecated::class)) {
            $nativeAttrs = $reflector->getAttributes(\Deprecated::class);
            if (!empty($nativeAttrs)) {
                return $nativeAttrs[0]->newInstance()->message ?? 'No longer supported';
            }
        }

        return null;
    }

    /**
     * @param class-string $className
     */
    private function resolveResolverInstance(string $className): object
    {
        return $this->instantiatedResolvers[$className] ??= $this->instantiator->create($className);
    }

    /**
     * Fail the build when a resolver, or a DataLoader one of its methods takes, cannot be created.
     *
     * @param ReflectionClass<object> $ref
     */
    private function checkResolver(ReflectionClass $ref, ReflectionMethod $method): void
    {
        if (!isset($this->instantiatedResolvers[$ref->getName()])) {
            $this->instantiator->check($ref->getName());
        }

        $this->checkLoaderParameters($method);
    }

    private function checkLoaderParameters(ReflectionMethod $method): void
    {
        if ($this->dataLoaderFactory !== null) {
            return;
        }
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), DataLoader::class)) {
                $this->instantiator->check($type->getName());
            }
        }
    }
}

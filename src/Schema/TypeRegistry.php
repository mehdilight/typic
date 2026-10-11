<?php

declare(strict_types=1);

namespace Typic\Schema;

use GraphQL\Type\Definition\BooleanType;
use GraphQL\Type\Definition\FloatType;
use GraphQL\Type\Definition\IDType;
use GraphQL\Type\Definition\IntType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NullableType;
use GraphQL\Type\Definition\StringType;
use GraphQL\Type\Definition\Type;
use InvalidArgumentException;

class TypeRegistry
{
    /**
     * @var array<string, Type&NamedType>
     */
    private array $types = [];

    /**
     * Maps PHP class names to GraphQL type names.
     *
     * @var array<class-string, string>
     */
    private array $classMap = [];

    public function __construct()
    {
        $this->registerStandardScalars();
    }

    private function registerStandardScalars(): void
    {
        $this->types['Int'] = Type::int();
        $this->types['Float'] = Type::float();
        $this->types['String'] = Type::string();
        $this->types['Boolean'] = Type::boolean();
        $this->types['ID'] = Type::id();
    }

    /**
     * Register a GraphQL type.
     *
     * @param class-string|null $phpClass
     */
    public function register(Type&NamedType $type, ?string $phpClass = null): void
    {
        $name = $type->name();
        $this->types[$name] = $type;

        if ($phpClass !== null) {
            $this->classMap[$phpClass] = $name;
        }
    }

    /**
     * Check if a type name is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /**
     * Check if a PHP class is mapped to a type.
     *
     * @param class-string $className
     */
    public function hasClass(string $className): bool
    {
        return isset($this->classMap[$className]);
    }

    /**
     * Get a type by GraphQL name.
     */
    public function get(string $name): Type&NamedType
    {
        if (!isset($this->types[$name])) {
            throw new InvalidArgumentException("GraphQL type '$name' is not registered.");
        }

        return $this->types[$name];
    }

    /**
     * Get a type by PHP class name.
     *
     * @param class-string $className
     */
    public function getByClass(string $className): Type&NamedType
    {
        if (!isset($this->classMap[$className])) {
            throw new InvalidArgumentException("No GraphQL type registered for PHP class '$className'.");
        }

        return $this->get($this->classMap[$className]);
    }

    /**
     * Map a PHP class to an existing registered type name.
     *
     * @param class-string $className
     */
    public function mapClass(string $className, string $typeName): void
    {
        $this->classMap[$className] = $typeName;
    }

    /**
     * @return array<string, Type&NamedType>
     */
    public function all(): array
    {
        return $this->types;
    }
}

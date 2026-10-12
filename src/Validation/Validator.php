<?php

declare(strict_types=1);

namespace Typic\Validation;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use Typic\Attribute\Arg;
use Typic\Attribute\Field;
use Typic\Attribute\InputType;

/**
 * Checks a resolver's GraphQL arguments against the rules on its parameters and on the
 * fields of its input types, before the arguments are turned into objects.
 *
 * What to check is worked out once per method, so methods without rules cost nothing.
 */
final class Validator
{
    /** @var array<string, list<FieldPlan>> */
    private array $plans = [];

    /**
     * @param array<string, mixed> $args The GraphQL arguments
     * @throws ValidationError
     */
    public function validate(ReflectionMethod $method, array $args): void
    {
        $id = $method->getDeclaringClass()->getName() . '::' . $method->getName();
        $plan = $this->plans[$id] ??= $this->planFor(
            $method->getParameters(),
            static fn (ReflectionParameter $param): string => self::attribute($param, Arg::class)->name ?? $param->getName(),
        );
        if ($plan === []) {
            return;
        }

        $errors = [];
        $this->check($plan, $args, '', $errors);
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
    }

    /**
     * @param list<FieldPlan> $plan
     * @param array<array-key, mixed> $data
     * @param array<string, list<string>> $errors
     */
    private function check(array $plan, array $data, string $prefix, array &$errors): void
    {
        foreach ($plan as $field) {
            $value = $data[$field->name] ?? null;
            $path = $prefix . $field->name;

            foreach ($field->rules as $rule) {
                $this->apply($rule, $value, $data, $path, $errors);
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($field->each as $rule) {
                foreach ($value as $index => $item) {
                    $this->apply($rule, $item, $data, "{$path}.{$index}", $errors);
                }
            }

            if ($field->input === null) {
                continue;
            }

            $inputPlan = $this->inputPlan($field->input);
            if ($field->list) {
                foreach ($value as $index => $item) {
                    if (is_array($item)) {
                        $this->check($inputPlan, $item, "{$path}.{$index}.", $errors);
                    }
                }
            } else {
                $this->check($inputPlan, $value, "{$path}.", $errors);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, list<string>> $errors
     */
    private function apply(Rule|DependentRule $rule, mixed $value, array $data, string $path, array &$errors): void
    {
        // Only implicit rules (NotBlank, RequiredIf...) look at missing values
        if ($value === null && !$rule instanceof ImplicitRule) {
            return;
        }

        /** @var array<string, mixed> $data */
        $problem = $rule instanceof DependentRule ? $rule->check($value, $data) : $rule->check($value);
        if ($problem !== null) {
            $errors[$path][] = $problem;
        }
    }

    /**
     * @param class-string $class
     * @return list<FieldPlan>
     */
    private function inputPlan(string $class): array
    {
        if (!isset($this->plans[$class])) {
            // Placeholder first, so input types that contain themselves do not recurse forever
            $this->plans[$class] = [];
            $ref = new ReflectionClass($class);
            $members = [...($ref->getConstructor()?->getParameters() ?? [])];
            $promoted = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $members);
            foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if (!in_array($property->getName(), $promoted, true)) {
                    $members[] = $property;
                }
            }

            $this->plans[$class] = $this->planFor(
                $members,
                static fn (ReflectionParameter|ReflectionProperty $member): string => self::attribute($member, Field::class)->name ?? $member->getName(),
            );
        }

        return $this->plans[$class];
    }

    /**
     * @template T of ReflectionParameter|ReflectionProperty
     * @param list<T> $members
     * @param callable(T): string $nameOf
     * @return list<FieldPlan>
     */
    private function planFor(array $members, callable $nameOf): array
    {
        $plan = [];
        foreach ($members as $member) {
            $rules = [
                ...array_map(static fn (ReflectionAttribute $a): Rule => $a->newInstance(), $member->getAttributes(Rule::class, ReflectionAttribute::IS_INSTANCEOF)),
                ...array_map(static fn (ReflectionAttribute $a): DependentRule => $a->newInstance(), $member->getAttributes(DependentRule::class, ReflectionAttribute::IS_INSTANCEOF)),
            ];
            $each = self::attribute($member, Each::class)->rules ?? [];

            $listOf = self::attribute($member, Field::class)->arrayOf ?? self::attribute($member, Arg::class)?->arrayOf;
            $type = $member->getType();
            $class = $listOf ?? ($type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null);
            $input = $class !== null && class_exists($class) && (new ReflectionClass($class))->getAttributes(InputType::class) !== [] ? $class : null;

            if ($rules === [] && $each === [] && $input === null) {
                continue;
            }

            $plan[] = new FieldPlan($nameOf($member), $rules, $each, $input, $listOf !== null);
        }

        return $plan;
    }

    /**
     * @template A of object
     * @param class-string<A> $attribute
     * @return A|null
     */
    private static function attribute(ReflectionParameter|ReflectionProperty $member, string $attribute): ?object
    {
        $attributes = $member->getAttributes($attribute);

        return $attributes !== [] ? $attributes[0]->newInstance() : null;
    }
}

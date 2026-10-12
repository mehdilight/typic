<?php

declare(strict_types=1);

namespace Typic\Middleware;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use Typic\Attribute\Middleware;
use Typic\Attribute\WithoutMiddleware;

/**
 * Works out which middleware wraps each resolver, and runs them.
 *
 * Order, outermost first: global middleware, the class's #[Middleware], then the method's
 * #[Middleware], each in the order written. Groups (which may include other groups) expand
 * in place, #[WithoutMiddleware] drops inherited (global and class) entries, and an entry
 * listed twice runs twice.
 */
final class MiddlewareStack
{
    /** @var array<class-string<ResolverMiddleware>, ResolverMiddleware> */
    private array $instances = [];

    /** @var array<string, list<ResolverMiddleware|class-string<ResolverMiddleware>>> Groups with nested groups expanded */
    private array $groups = [];

    /** @var list<ResolverMiddleware|class-string<ResolverMiddleware>> */
    private array $global;

    /**
     * Entries are middleware instances, middleware classes, or group names.
     *
     * @param list<ResolverMiddleware|string> $global
     * @param array<string, list<ResolverMiddleware|string>> $definedGroups
     * @throws InvalidArgumentException When an entry is neither middleware nor a group, or a group includes itself
     */
    public function __construct(
        array $global = [],
        private array $definedGroups = [],
        private ?ContainerInterface $container = null,
    ) {
        foreach (array_keys($definedGroups) as $name) {
            $this->groups[$name] = $this->expandGroup($name, []);
        }
        $this->global = $this->expand($global, 'in withResolverMiddleware()');
    }

    /**
     * The middleware around $method, outermost first.
     *
     * @param ReflectionClass<object> $class The class the field is registered from
     * @return list<ResolverMiddleware>
     */
    public function for(ReflectionClass $class, ReflectionMethod $method): array
    {
        $where = 'on ' . $class->getName() . '::' . $method->getName() . '()';

        $without = [];
        foreach ($method->getAttributes(WithoutMiddleware::class) as $attribute) {
            array_push($without, ...$this->expand($attribute->newInstance()->middleware, "in #[WithoutMiddleware] {$where}"));
        }

        $inherited = array_filter(
            [...$this->global, ...$this->fromAttributes($class->getAttributes(Middleware::class), 'on ' . $class->getName())],
            static fn (ResolverMiddleware|string $middleware): bool => !self::isAny($middleware, $without),
        );
        $stack = [...$inherited, ...$this->fromAttributes($method->getAttributes(Middleware::class), $where)];

        return array_map(fn (ResolverMiddleware|string $middleware): ResolverMiddleware => $this->instance($middleware), $stack);
    }

    /**
     * Run $resolver inside $stack.
     *
     * @param list<ResolverMiddleware> $stack
     * @param callable(ResolverCall): mixed $resolver
     */
    public static function run(array $stack, ResolverCall $call, callable $resolver): mixed
    {
        $next = $resolver;
        foreach (array_reverse($stack) as $middleware) {
            $next = static fn (ResolverCall $call): mixed => $middleware->handle($call, $next);
        }

        return $next($call);
    }

    /**
     * @param list<\ReflectionAttribute<Middleware>> $attributes
     * @return list<ResolverMiddleware|class-string<ResolverMiddleware>>
     */
    private function fromAttributes(array $attributes, string $where): array
    {
        $stack = [];
        foreach ($attributes as $attribute) {
            array_push($stack, ...$this->expand($attribute->newInstance()->middleware, $where));
        }

        return $stack;
    }

    /**
     * Replace group names with their middleware.
     *
     * @param list<ResolverMiddleware|string> $entries
     * @param string $where Where the entries come from, for error messages
     * @return list<ResolverMiddleware|class-string<ResolverMiddleware>>
     */
    private function expand(array $entries, string $where): array
    {
        $expanded = [];
        foreach ($entries as $entry) {
            if (is_string($entry) && isset($this->groups[$entry])) {
                array_push($expanded, ...$this->groups[$entry]);
            } elseif ($entry instanceof ResolverMiddleware || is_a($entry, ResolverMiddleware::class, true)) {
                $expanded[] = $entry;
            } else {
                throw new InvalidArgumentException(sprintf(
                    "Unknown middleware '%s' %s: register a group with that name using withMiddlewareGroup(), or use a class implementing %s.",
                    $entry,
                    $where,
                    ResolverMiddleware::class,
                ));
            }
        }

        return $expanded;
    }

    /**
     * @param list<string> $parents Groups being expanded around this one, to catch cycles
     * @return list<ResolverMiddleware|class-string<ResolverMiddleware>>
     */
    private function expandGroup(string $name, array $parents): array
    {
        if (isset($this->groups[$name])) {
            return $this->groups[$name];
        }
        if (in_array($name, $parents, true)) {
            throw new InvalidArgumentException("Middleware group '{$name}' includes itself.");
        }

        $expanded = [];
        foreach ($this->definedGroups[$name] as $entry) {
            if (is_string($entry) && isset($this->definedGroups[$entry])) {
                array_push($expanded, ...$this->expandGroup($entry, [...$parents, $name]));
            } else {
                array_push($expanded, ...$this->expand([$entry], "in group '{$name}'"));
            }
        }

        return $expanded;
    }

    /**
     * @param list<ResolverMiddleware|class-string<ResolverMiddleware>> $classes
     */
    private static function isAny(ResolverMiddleware|string $middleware, array $classes): bool
    {
        foreach ($classes as $class) {
            if (is_a($middleware, is_string($class) ? $class : $class::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ResolverMiddleware|class-string<ResolverMiddleware> $middleware
     */
    private function instance(ResolverMiddleware|string $middleware): ResolverMiddleware
    {
        if ($middleware instanceof ResolverMiddleware) {
            return $middleware;
        }

        return $this->instances[$middleware] ??= $this->create($middleware);
    }

    /**
     * @param class-string<ResolverMiddleware> $class
     */
    private function create(string $class): ResolverMiddleware
    {
        if ($this->container !== null && $this->container->has($class)) {
            $middleware = $this->container->get($class);
            if (!$middleware instanceof ResolverMiddleware) {
                throw new InvalidArgumentException("The container entry for {$class} does not implement " . ResolverMiddleware::class . '.');
            }

            return $middleware;
        }

        $constructor = (new ReflectionClass($class))->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new InvalidArgumentException(sprintf(
                'Middleware %1$s needs constructor arguments: write new %1$s(...) in the attribute, or register %1$s in the container.',
                $class,
            ));
        }

        return new $class();
    }
}

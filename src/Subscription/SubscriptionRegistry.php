<?php

declare(strict_types=1);

namespace Typic\Subscription;

use ReflectionClass;
use ReflectionMethod;
use Typic\Attribute\Subscription as SubscriptionAttr;

class SubscriptionRegistry
{
    /**
     * @var array<string, array{
     *     fieldName: string,
     *     topic: string,
     *     resolverClass: class-string,
     *     method: ReflectionMethod,
     *     attribute: SubscriptionAttr
     * }>
     */
    private array $subscriptions = [];

    /**
     * @param array<class-string|object> $resolvers
     */
    public static function fromResolvers(array $resolvers): self
    {
        $registry = new self();

        foreach ($resolvers as $resolver) {
            $ref = new ReflectionClass($resolver);
            $className = $ref->getName();

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $attrs = $method->getAttributes(SubscriptionAttr::class);
                if (empty($attrs)) {
                    continue;
                }

                /** @var SubscriptionAttr $attr */
                $attr = $attrs[0]->newInstance();
                $fieldName = $attr->name ?? $method->getName();
                $topic = $attr->topic ?? $fieldName;

                $registry->register($fieldName, $topic, $className, $method, $attr);
            }
        }

        return $registry;
    }

    /**
     * @param class-string $resolverClass
     */
    public function register(
        string $fieldName,
        string $topic,
        string $resolverClass,
        ReflectionMethod $method,
        SubscriptionAttr $attr,
    ): self {
        $this->subscriptions[$fieldName] = [
            'fieldName' => $fieldName,
            'topic' => $topic,
            'resolverClass' => $resolverClass,
            'method' => $method,
            'attribute' => $attr,
        ];

        return $this;
    }

    public function has(string $fieldName): bool
    {
        return isset($this->subscriptions[$fieldName]);
    }

    /**
     * @return array{
     *     fieldName: string,
     *     topic: string,
     *     resolverClass: class-string,
     *     method: ReflectionMethod,
     *     attribute: SubscriptionAttr
     * }|null
     */
    public function get(string $fieldName): ?array
    {
        return $this->subscriptions[$fieldName] ?? null;
    }

    /**
     * @return array<string, array{
     *     fieldName: string,
     *     topic: string,
     *     resolverClass: class-string,
     *     method: ReflectionMethod,
     *     attribute: SubscriptionAttr
     * }>
     */
    public function all(): array
    {
        return $this->subscriptions;
    }
}

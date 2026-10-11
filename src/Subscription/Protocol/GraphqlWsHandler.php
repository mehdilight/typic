<?php

declare(strict_types=1);

namespace Typic\Subscription\Protocol;

use GraphQL\Error\FormattedError;
use GraphQL\Error\SyntaxError;
use GraphQL\GraphQL;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;
use Typic\Context;
use Typic\Schema\ArgumentHydrator;
use Typic\Subscription\Driver\WebSocketConnectionInterface;
use Typic\Subscription\PubSub\PubSubInterface;
use Typic\Subscription\SubscriptionRegistry;

class GraphqlWsHandler
{
    /**
     * Active subscriptions map: [connId => [subId => ['token' => pubSubToken, 'topic' => topic]]]
     * @var array<string, array<string, array{token: string, topic: string}>>
     */
    private array $activeSubscriptions = [];

    /**
     * @var array<class-string, object>
     */
    private array $resolverInstances = [];

    private ArgumentHydrator $argumentHydrator;

    public function __construct(
        private Schema $schema,
        private SubscriptionRegistry $registry,
        private PubSubInterface $pubSub,
        private ?ContainerInterface $container = null,
        ?ArgumentHydrator $argumentHydrator = null,
    ) {
        $this->argumentHydrator = $argumentHydrator ?? new ArgumentHydrator($this->container);
    }

    public function handleConnect(WebSocketConnectionInterface $connection): void
    {
        // Connection opened
    }

    public function handleClose(WebSocketConnectionInterface $connection): void
    {
        $connId = $connection->getId();
        if (isset($this->activeSubscriptions[$connId])) {
            foreach ($this->activeSubscriptions[$connId] as $sub) {
                $this->pubSub->unsubscribe($sub['token']);
            }
            unset($this->activeSubscriptions[$connId]);
        }
    }

    public function handleMessage(WebSocketConnectionInterface $connection, string $message): void
    {
        $data = json_decode($message, true);
        if (!is_array($data) || !isset($data['type']) || !is_string($data['type'])) {
            return;
        }

        $type = $data['type'];

        switch ($type) {
            case 'connection_init':
                $this->sendAck($connection);
                break;

            case 'ping':
                $this->sendPong($connection);
                break;

            case 'pong':
                // Client heartbeat response
                break;

            case 'subscribe':
                $this->handleSubscribe($connection, $data);
                break;

            case 'complete':
                $this->handleComplete($connection, $data);
                break;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleSubscribe(WebSocketConnectionInterface $connection, array $data): void
    {
        $subId = isset($data['id']) ? (string) $data['id'] : null;
        if ($subId === null) {
            return;
        }

        /** @var array{query?: string, variables?: array<string, mixed>, operationName?: string|null} $payload */
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $query = $payload['query'] ?? '';

        if ($query === '') {
            $this->sendError($connection, $subId, 'Query string is required');
            return;
        }

        try {
            $document = Parser::parse($query);
        } catch (SyntaxError $e) {
            $this->sendError($connection, $subId, $e->getMessage());
            return;
        }

        $fieldName = $this->extractSubscriptionFieldName($document, $payload['operationName'] ?? null);
        if ($fieldName === null) {
            $this->sendError($connection, $subId, 'No subscription field found in operation');
            return;
        }

        $subscriptionMeta = $this->registry->get($fieldName);
        if ($subscriptionMeta === null) {
            $this->sendError($connection, $subId, "Unknown subscription field '{$fieldName}'");
            return;
        }

        $topic = $subscriptionMeta['topic'];
        $connId = $connection->getId();

        // If client re-subscribes with same id, cancel prior
        if (isset($this->activeSubscriptions[$connId][$subId])) {
            $this->pubSub->unsubscribe($this->activeSubscriptions[$connId][$subId]['token']);
        }

        $token = $this->pubSub->subscribe($topic, function (mixed $eventPayload) use ($connection, $subId, $payload, $subscriptionMeta): void {
            $this->dispatchSubscriptionEvent($connection, $subId, $eventPayload, $payload, $subscriptionMeta);
        });

        $this->activeSubscriptions[$connId][$subId] = [
            'token' => $token,
            'topic' => $topic,
        ];
    }

    /**
     * @param array{query?: string, variables?: array<string, mixed>, operationName?: string|null} $payload
     * @param array{fieldName: string, topic: string, resolverClass: class-string, method: \ReflectionMethod, attribute: \Typic\Attribute\Subscription} $subscriptionMeta
     */
    private function dispatchSubscriptionEvent(
        WebSocketConnectionInterface $connection,
        string $subId,
        mixed $eventPayload,
        array $payload,
        array $subscriptionMeta,
    ): void {
        $method = $subscriptionMeta['method'];
        $resolverClass = $subscriptionMeta['resolverClass'];
        $instance = $this->resolveResolverInstance($resolverClass);

        $variables = $payload['variables'] ?? [];
        $query = $payload['query'] ?? '';
        $opName = $payload['operationName'] ?? null;

        $context = new Context([
            'connection' => $connection,
            'container' => $this->container,
            'pubSub' => $this->pubSub,
        ]);

        $subscriptionType = $this->schema->getSubscriptionType()
            ?? new \GraphQL\Type\Definition\ObjectType(['name' => 'Subscription', 'fields' => []]);
        $fieldDef = $subscriptionType->hasField($subscriptionMeta['fieldName'])
            ? $subscriptionType->getField($subscriptionMeta['fieldName'])
            : new \GraphQL\Type\Definition\FieldDefinition([
                'name' => $subscriptionMeta['fieldName'],
                'type' => \GraphQL\Type\Definition\Type::string(),
            ]);

        $resolveInfo = new \GraphQL\Type\Definition\ResolveInfo(
            fieldDefinition: $fieldDef,
            fieldNodes: new \ArrayObject([]),
            parentType: $subscriptionType,
            path: [$subscriptionMeta['fieldName']],
            schema: $this->schema,
            fragments: [],
            rootValue: $eventPayload,
            operation: new OperationDefinitionNode(['operation' => 'subscription']),
            variableValues: $variables,
        );

        $resolvedArgs = $this->argumentHydrator->resolveArguments(
            $method,
            $variables,
            $eventPayload,
            $context,
            $resolveInfo,
            is_object($eventPayload) ? get_class($eventPayload) : null,
        );

        $rootValue = $eventPayload;

        try {
            $filterResult = $method->invokeArgs($instance, $resolvedArgs);

            // If resolver returned false, event is filtered out for this subscriber
            if ($filterResult === false) {
                return;
            }

            // If resolver returned an object or array, use it as the source/rootValue
            if (is_object($filterResult) || (is_array($filterResult) && !empty($filterResult))) {
                $rootValue = $filterResult;
            }
        } catch (Throwable $e) {
            $this->sendError($connection, $subId, $e->getMessage());
            return;
        }

        // Execute GraphQL selection set against rootValue
        try {
            $result = GraphQL::executeQuery(
                $this->schema,
                $query,
                $rootValue,
                $context,
                $variables,
                $opName
            );

            $this->sendNext($connection, $subId, $result->toArray());
        } catch (Throwable $e) {
            $this->sendError($connection, $subId, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleComplete(WebSocketConnectionInterface $connection, array $data): void
    {
        $subId = isset($data['id']) ? (string) $data['id'] : null;
        if ($subId === null) {
            return;
        }

        $connId = $connection->getId();
        if (isset($this->activeSubscriptions[$connId][$subId])) {
            $this->pubSub->unsubscribe($this->activeSubscriptions[$connId][$subId]['token']);
            unset($this->activeSubscriptions[$connId][$subId]);
        }
    }

    private function extractSubscriptionFieldName(\GraphQL\Language\AST\DocumentNode $document, ?string $operationName): ?string
    {
        foreach ($document->definitions as $def) {
            if ($def instanceof OperationDefinitionNode && $def->operation === 'subscription') {
                $defOpName = $def->name !== null ? $def->name->value : '';
                if ($operationName !== null && $defOpName !== $operationName) {
                    continue;
                }

                foreach ($def->selectionSet->selections as $selection) {
                    if ($selection instanceof FieldNode) {
                        return $selection->name->value;
                    }
                }
            }
        }

        return null;
    }

    private function sendAck(WebSocketConnectionInterface $connection): void
    {
        $connection->send((string) json_encode(['type' => 'connection_ack']));
    }

    private function sendPong(WebSocketConnectionInterface $connection): void
    {
        $connection->send((string) json_encode(['type' => 'pong']));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendNext(WebSocketConnectionInterface $connection, string $subId, array $payload): void
    {
        $connection->send((string) json_encode([
            'id' => $subId,
            'type' => 'next',
            'payload' => $payload,
        ]));
    }

    private function sendError(WebSocketConnectionInterface $connection, string $subId, string $message): void
    {
        $connection->send((string) json_encode([
            'id' => $subId,
            'type' => 'error',
            'payload' => [
                ['message' => $message],
            ],
        ]));
    }

    /**
     * @param class-string $className
     */
    private function resolveResolverInstance(string $className): object
    {
        if (isset($this->resolverInstances[$className])) {
            return $this->resolverInstances[$className];
        }

        if ($this->container !== null && $this->container->has($className)) {
            $instance = $this->container->get($className);
            $this->resolverInstances[$className] = $instance;
            return $instance;
        }

        $ref = new ReflectionClass($className);
        $constructor = $ref->getConstructor();
        if ($constructor === null || empty($constructor->getParameters())) {
            $instance = new $className();
            $this->resolverInstances[$className] = $instance;
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
        $this->resolverInstances[$className] = $instance;
        return $instance;
    }

    /**
     * @return array<string, array<string, array{token: string, topic: string}>>
     */
    public function getActiveSubscriptions(): array
    {
        return $this->activeSubscriptions;
    }
}

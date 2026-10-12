<?php

declare(strict_types=1);

namespace Typic\Subscription\Protocol;

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Error\SyntaxError;
use GraphQL\GraphQL;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use Psr\Container\ContainerInterface;
use Throwable;
use Typic\Context;
use Typic\Subscription\Driver\HandshakeAwareInterface;
use Typic\Subscription\Driver\WebSocketConnectionInterface;
use Typic\Subscription\PubSub\PubSubInterface;
use Typic\Subscription\SubscriptionRegistry;
use Typic\Subscription\SubscriptionSignal;

class GraphqlWsHandler
{
    /**
     * Active subscriptions map: [connId => [subId => ['token' => pubSubToken, 'topic' => topic]]]
     * @var array<string, array<string, array{token: string, topic: string}>>
     */
    private array $activeSubscriptions = [];

    /**
     * The connection_init payload of each connection.
     * @var array<string, array<string, mixed>>
     */
    private array $connectionParams = [];

    public function __construct(
        private Schema $schema,
        private SubscriptionRegistry $registry,
        private PubSubInterface $pubSub,
        private ?ContainerInterface $container = null,
    ) {
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
        unset($this->connectionParams[$connId]);
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
                /** @var array<string, mixed> $params */
                $params = is_array($data['payload'] ?? null) ? $data['payload'] : [];
                $this->connectionParams[$connection->getId()] = $params;
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

        // Validate the operation and run the field's middleware, which may refuse the subscription
        $outcome = GraphQL::executeQuery(
            $this->schema,
            $document,
            null,
            $this->createContext($connection, subscribing: true),
            $payload['variables'] ?? null,
            $payload['operationName'] ?? null,
        );
        $errors = $this->withoutSignals($outcome->errors);
        if ($errors !== []) {
            $this->sendErrors($connection, $subId, $errors);
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
     * Resolve the subscription for one event. The subscription field's resolver (and its
     * middleware) runs with the event as its parent; returning false skips the event.
     *
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
        try {
            $result = GraphQL::executeQuery(
                $this->schema,
                $payload['query'] ?? '',
                $eventPayload,
                $this->createContext($connection, subscribing: false),
                $payload['variables'] ?? null,
                $payload['operationName'] ?? null,
            );
        } catch (Throwable $e) {
            $this->sendErrors($connection, $subId, [$e]);
            return;
        }

        foreach ($result->errors as $error) {
            if ($error->getPrevious() instanceof SubscriptionSignal && !$error->getPrevious()->accepted) {
                return;
            }
        }

        $this->sendNext($connection, $subId, $result->toArray());
    }

    private function createContext(WebSocketConnectionInterface $connection, bool $subscribing): Context
    {
        return new Context([
            'connection' => $connection,
            'connectionParams' => $this->connectionParams[$connection->getId()] ?? [],
            'request' => $connection instanceof HandshakeAwareInterface ? $connection->getHandshakeRequest() : null,
            'subscribing' => $subscribing,
            'container' => $this->container,
            'pubSub' => $this->pubSub,
        ]);
    }

    /**
     * @param array<Error> $errors
     * @return list<Error>
     */
    private function withoutSignals(array $errors): array
    {
        return array_values(array_filter(
            $errors,
            static fn (Error $error): bool => !$error->getPrevious() instanceof SubscriptionSignal,
        ));
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
     * Send errors the way GraphQL responses show them: messages of internal exceptions stay hidden.
     *
     * @param array<Throwable> $errors
     */
    private function sendErrors(WebSocketConnectionInterface $connection, string $subId, array $errors): void
    {
        $connection->send((string) json_encode([
            'id' => $subId,
            'type' => 'error',
            'payload' => array_map(static fn (Throwable $error): array => FormattedError::createFromException($error), array_values($errors)),
        ]));
    }

    /**
     * @return array<string, array<string, array{token: string, topic: string}>>
     */
    public function getActiveSubscriptions(): array
    {
        return $this->activeSubscriptions;
    }
}

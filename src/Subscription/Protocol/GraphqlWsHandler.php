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
use Typic\Server\ErrorReporter;
use Typic\Server\QueryLimits;
use Typic\Subscription\Driver\HandshakeAwareInterface;
use Typic\Subscription\Driver\WebSocketConnectionInterface;
use Typic\Subscription\PubSub\PubSubInterface;
use Typic\Subscription\SubscriptionRegistry;
use Typic\Subscription\SubscriptionOptions;
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

    private ErrorReporter $errors;
    private SubscriptionOptions $options;

    /** @var callable(): float */
    private $clock;

    /** @var array<string, array{connection: WebSocketConnectionInterface, opened: float, activity: float, ping: float, initialized: bool}> */
    private array $connections = [];

    /** @param (callable(): float)|null $clock */
    public function __construct(
        private Schema $schema,
        private SubscriptionRegistry $registry,
        private PubSubInterface $pubSub,
        private ?ContainerInterface $container = null,
        private ?QueryLimits $limits = null,
        ?ErrorReporter $errors = null,
        ?SubscriptionOptions $options = null,
        ?callable $clock = null,
        private ?int $maxMessageSize = null,
    ) {
        $this->errors = $errors ?? new ErrorReporter();
        $this->options = $options ?? new SubscriptionOptions();
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function handleConnect(WebSocketConnectionInterface $connection): void
    {
        $now = ($this->clock)();
        $this->connections[$connection->getId()] = [
            'connection' => $connection, 'opened' => $now, 'activity' => $now,
            'ping' => $now, 'initialized' => false,
        ];
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
        unset($this->connectionParams[$connId], $this->connections[$connId]);
    }

    public function handleMessage(WebSocketConnectionInterface $connection, string $message): void
    {
        $id = $connection->getId();
        if (!isset($this->connections[$id])) {
            return;
        }
        if ($this->maxMessageSize !== null && strlen($message) > $this->maxMessageSize) {
            $this->close($connection, 1009, 'Message too large');
            return;
        }
        $shape = json_decode($message);
        $data = json_decode($message, true);
        $objectPayload = !is_object($shape) || !isset($shape->payload) || $shape->payload instanceof \stdClass;
        $objectVariables = !is_object($shape) || !isset($shape->payload->variables) || $shape->payload->variables instanceof \stdClass;
        $objectExtensions = !is_object($shape) || !isset($shape->payload->extensions) || $shape->payload->extensions instanceof \stdClass;
        if (!$shape instanceof \stdClass || !$objectPayload || !$objectVariables || !$objectExtensions || !is_array($data) || !$this->validMessage($data)) {
            $this->close($connection, 4400, 'Invalid message');
            return;
        }
        $this->connections[$id]['activity'] = ($this->clock)();

        switch ($data['type']) {
            case 'connection_init':
                if ($this->connections[$id]['initialized']) {
                    $this->close($connection, 4429, 'Too many initialisation requests');
                    return;
                }
                /** @var array<string, mixed> $params */
                $params = $data['payload'] ?? [];
                $hook = $this->options->onConnectionInit;
                try {
                    $accepted = $hook === null || $hook($params, $connection instanceof HandshakeAwareInterface ? $connection->getHandshakeRequest() : null);
                } catch (Throwable $e) {
                    $this->errors->exception($e, 'connection initialisation');
                    $accepted = false;
                }
                if (!$accepted) {
                    $this->close($connection, 4403, 'Forbidden');
                    return;
                }
                $this->connections[$id]['initialized'] = true;
                $this->connectionParams[$id] = $params;
                $this->sendAck($connection);
                break;
            case 'ping':
                $connection->send((string) json_encode(isset($data['payload']) ? ['type' => 'pong', 'payload' => $data['payload']] : ['type' => 'pong']));
                break;
            case 'pong':
                break;
            case 'subscribe':
                if (!$this->connections[$id]['initialized']) {
                    $this->close($connection, 4401, 'Unauthorized');
                    return;
                }
                $this->handleSubscribe($connection, $data);
                break;
            case 'complete':
                $this->handleComplete($connection, $data);
                break;
        }
    }

    /** @param array<mixed> $data */
    private function validMessage(array $data): bool
    {
        $type = $data['type'] ?? null;
        if (!in_array($type, ['connection_init', 'ping', 'pong', 'subscribe', 'complete'], true)) {
            return false;
        }
        if (in_array($type, ['subscribe', 'complete'], true) && (!is_string($data['id'] ?? null) || $data['id'] === '')) {
            return false;
        }
        $payload = $data['payload'] ?? null;
        if (array_key_exists('payload', $data) && $payload !== null && (!is_array($payload) || ($payload !== [] && array_is_list($payload)))) {
            return false;
        }
        if ($type === 'subscribe') {
            return is_array($payload) && is_string($payload['query'] ?? null)
                && (!isset($payload['operationName']) || is_string($payload['operationName']))
                && (!isset($payload['variables']) || (is_array($payload['variables']) && ($payload['variables'] === [] || !array_is_list($payload['variables']))))
                && (!isset($payload['extensions']) || (is_array($payload['extensions']) && ($payload['extensions'] === [] || !array_is_list($payload['extensions']))));
        }
        return true;
    }

    /** Custom event-loop drivers must call this periodically if they do not implement TickAwareInterface. */
    public function tick(?float $now = null): void
    {
        $now ??= ($this->clock)();
        foreach ($this->connections as $id => $state) {
            if (!$state['initialized'] && $now - $state['opened'] >= $this->options->connectionInitTimeout) {
                $this->close($state['connection'], 4408, 'Connection initialisation timeout');
            } elseif ($state['initialized'] && $this->options->keepAlive > 0) {
                if ($now - $state['activity'] >= 2 * $this->options->keepAlive) {
                    $this->close($state['connection'], 1001, 'Connection idle');
                } elseif ($now - $state['ping'] >= $this->options->keepAlive) {
                    $state['connection']->send('{"type":"ping"}');
                    $this->connections[$id]['ping'] = $now;
                }
            }
        }
    }

    private function close(WebSocketConnectionInterface $connection, int $code, string $reason): void
    {
        $this->handleClose($connection);
        $connection->close($code, $reason);
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

        $connId = $connection->getId();
        if (isset($this->activeSubscriptions[$connId][$subId])) {
            $this->close($connection, 4409, "Subscriber for {$subId} already exists");
            return;
        }
        if (count($this->activeSubscriptions[$connId] ?? []) >= $this->options->maxSubscriptionsPerConnection) {
            $this->sendError($connection, $subId, "Too many subscriptions: at most {$this->options->maxSubscriptionsPerConnection} per connection.");
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
            null,
            $this->limits?->rules(),
        );
        $errors = $this->withoutSignals($outcome->errors);
        $this->errors->report($errors, $payload['operationName'] ?? null);
        if ($errors !== []) {
            $this->sendErrors($connection, $subId, $errors);
            return;
        }

        $topic = $subscriptionMeta['topic'];
        $connId = $connection->getId();

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
                null,
                $this->limits?->rules(),
            );
        } catch (Throwable $e) {
            $this->errors->exception($e, 'subscription event');
            $this->sendErrors($connection, $subId, [$e]);
            $this->handleComplete($connection, ['id' => $subId]);
            return;
        }

        foreach ($result->errors as $error) {
            if ($error->getPrevious() instanceof SubscriptionSignal && !$error->getPrevious()->accepted) {
                return;
            }
        }
        $this->errors->report($result->errors, $payload['operationName'] ?? null);

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

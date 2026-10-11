<?php

declare(strict_types=1);

namespace Typic\Server;

use Psr\Container\ContainerInterface;
use Typic\Explorer\AltairRenderer;
use Typic\Explorer\ApolloSandboxRenderer;
use Typic\Explorer\ExplorerInterface;
use Typic\Explorer\ExplorerType;
use Typic\Explorer\GraphiQLRenderer;
use Typic\Scanner\DirectoryScanner;
use Typic\Schema\SchemaCompiler;
use Typic\Subscription\Driver\WebSocketServerInterface;
use Typic\Subscription\PubSub\PubSubInterface;
use Typic\Subscription\SubscriptionRegistry;
use Typic\Subscription\SubscriptionServer;

class TypicBuilder
{
    /**
     * @var list<class-string|object>
     */
    private array $resolvers = [];

    /**
     * @var list<class-string>
     */
    private array $types = [];

    /**
     * @var list<string>
     */
    private array $scanDirectories = [];

    private ?ContainerInterface $container = null;
    private bool $sandboxEnabled = true;
    private string $sandboxEndpoint = '/graphql';
    /** @var array<string, mixed> */
    private array $sandboxOptions = [];
    private ?ExplorerInterface $explorer = null;
    private bool $debug = false;
    private bool $corsEnabled = true;
    /** @var array<string, mixed> */
    private array $corsOptions = [];
    private bool $subscriptionsEnabled = false;
    private ?WebSocketServerInterface $subscriptionDriver = null;
    private ?PubSubInterface $pubSub = null;
    private ?string $subscriptionEndpoint = null;

    /**
     * @var array<string, mixed>
     */
    private array $options = [];

    /**
     * @param array<class-string|object> $resolvers
     * @return $this
     */
    public function registerResolvers(array $resolvers): self
    {
        foreach ($resolvers as $resolver) {
            $this->registerResolver($resolver);
        }
        return $this;
    }

    /**
     * @param class-string|object $resolver
     * @return $this
     */
    public function registerResolver(string|object $resolver): self
    {
        $this->resolvers[] = $resolver;
        return $this;
    }

    /**
     * @param array<class-string> $types
     * @return $this
     */
    public function registerTypes(array $types): self
    {
        foreach ($types as $type) {
            $this->registerType($type);
        }
        return $this;
    }

    /**
     * @param class-string $type
     * @return $this
     */
    public function registerType(string $type): self
    {
        $this->types[] = $type;
        return $this;
    }

    /**
     * Automatically scan directories for classes tagged with Typic attributes.
     *
     * @param list<string> $directories
     * @return $this
     */
    public function scanDirectories(array $directories): self
    {
        $this->scanDirectories = array_merge($this->scanDirectories, $directories);
        return $this;
    }

    /**
     * Set a PSR-11 dependency injection container.
     *
     * @return $this
     */
    public function withContainer(?ContainerInterface $container): self
    {
        $this->container = $container;
        return $this;
    }

    /**
     * Enable or configure Apollo Sandbox explorer.
     *
     * @param array<string, mixed> $options
     * @return $this
     */
    /**
     * Configure GraphQL explorer (GraphiQL, Altair, or Apollo Sandbox).
     *
     * @param ExplorerInterface|ExplorerType|'graphiql'|'altair'|'apollo'|'sandbox' $explorer
     * @param array<string, mixed> $options
     * @return $this
     */
    public function withExplorer(
        ExplorerInterface|ExplorerType|string $explorer,
        string $endpoint = '/graphql',
        array $options = []
    ): self {
        $this->sandboxEnabled = true;
        $this->sandboxEndpoint = $endpoint;
        $this->sandboxOptions = $options;

        if ($explorer instanceof ExplorerInterface) {
            $this->explorer = $explorer;
        } elseif ($explorer instanceof ExplorerType) {
            $this->explorer = match ($explorer) {
                ExplorerType::GraphiQL => new GraphiQLRenderer(),
                ExplorerType::Altair => new AltairRenderer(),
                ExplorerType::Apollo, ExplorerType::Sandbox => new ApolloSandboxRenderer(),
            };
        } else {
            $this->explorer = match (strtolower($explorer)) {
                'graphiql' => new GraphiQLRenderer(),
                'altair' => new AltairRenderer(),
                'apollo', 'sandbox' => new ApolloSandboxRenderer(),
                default => throw new \InvalidArgumentException("Unknown explorer type '{$explorer}'. Available: graphiql, altair, apollo, sandbox."),
            };
        }

        return $this;
    }

    /**
     * Enable GraphiQL IDE (native DOM, zero iframes).
     *
     * @param array<string, mixed> $options
     * @return $this
     */
    public function withGraphiQL(bool $enabled = true, string $endpoint = '/graphql', array $options = []): self
    {
        if (!$enabled) {
            $this->sandboxEnabled = false;
            return $this;
        }
        return $this->withExplorer(ExplorerType::GraphiQL, $endpoint, $options);
    }

    /**
     * Enable Altair GraphQL Client (native DOM, zero iframes).
     *
     * @param array<string, mixed> $options
     * @return $this
     */
    public function withAltair(bool $enabled = true, string $endpoint = '/graphql', array $options = []): self
    {
        if (!$enabled) {
            $this->sandboxEnabled = false;
            return $this;
        }
        return $this->withExplorer(ExplorerType::Altair, $endpoint, $options);
    }

    /**
     * Enable Apollo Sandbox explorer.
     *
     * @param array<string, mixed> $options
     * @return $this
     */
    public function withSandbox(bool $enabled = true, string $endpoint = '/graphql', array $options = []): self
    {
        if (!$enabled) {
            $this->sandboxEnabled = false;
            return $this;
        }
        return $this->withExplorer(ExplorerType::Apollo, $endpoint, $options);
    }

    /**
     * Enable debug mode (includes traces and stack info in GraphQL error responses).
     *
     * @return $this
     */
    public function withDebug(bool $debug = true): self
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * Enable or disable CORS headers and preflight handling.
     *
     * @param array<string, mixed> $options
     * @return $this
     */
    public function withCors(bool $enabled = true, array $options = []): self
    {
        $this->corsEnabled = $enabled;
        $this->corsOptions = $options;
        return $this;
    }

    /**
     * Enable GraphQL real-time subscriptions with a pluggable WebSocket driver and Pub/Sub backplane.
     *
     * @param WebSocketServerInterface|null $driver Defaults to WorkermanDriver(port: 8080)
     * @param PubSubInterface|null $pubSub Defaults to InMemoryPubSub
     * @param string|null $endpoint WebSocket endpoint URL (e.g. 'ws://localhost:8080')
     * @return $this
     */
    public function withSubscriptions(
        ?WebSocketServerInterface $driver = null,
        ?PubSubInterface $pubSub = null,
        ?string $endpoint = null,
    ): self {
        $this->subscriptionsEnabled = true;
        $this->subscriptionDriver = $driver;
        $this->pubSub = $pubSub;
        $this->subscriptionEndpoint = $endpoint;
        return $this;
    }

    /**
     * Build the configured TypicServer instance.
     */
    public function build(): TypicServer
    {
        $resolvers = $this->resolvers;
        $types = $this->types;

        if (!empty($this->scanDirectories)) {
            $scanner = new DirectoryScanner();
            $scanned = $scanner->scan($this->scanDirectories);

            $mergedResolvers = array_merge($resolvers, $scanned['resolvers']);
            $uniqueResolvers = [];
            foreach ($mergedResolvers as $r) {
                $key = is_object($r) ? spl_object_hash($r) : $r;
                $uniqueResolvers[$key] = $r;
            }
            $resolvers = array_values($uniqueResolvers);
            $types = array_values(array_unique(array_merge($types, $scanned['types'])));
        }

        $compiler = new SchemaCompiler(
            resolvers: $resolvers,
            types: $types,
            container: $this->container,
        );

        $schema = $compiler->compile();

        $options = $this->options;
        if (!empty($this->sandboxOptions)) {
            $options['sandbox'] = $this->sandboxOptions;
        }

        $subscriptionServer = null;
        if ($this->subscriptionsEnabled) {
            $registry = SubscriptionRegistry::fromResolvers($resolvers);
            $subscriptionServer = new SubscriptionServer(
                schema: $schema,
                registry: $registry,
                driver: $this->subscriptionDriver,
                pubSub: $this->pubSub,
                container: $this->container,
            );

            $subEndpoint = $this->subscriptionEndpoint
                ?? ('ws://localhost:' . $subscriptionServer->getDriver()->getPort());
            $options['subscriptionEndpoint'] ??= $subEndpoint;
            $options['sandbox'] ??= [];
            if (is_array($options['sandbox'])) {
                $options['sandbox']['subscriptionEndpoint'] ??= $subEndpoint;
            }
        }

        return new TypicServer(
            schema: $schema,
            container: $this->container,
            sandboxEnabled: $this->sandboxEnabled,
            sandboxEndpoint: $this->sandboxEndpoint,
            debug: $this->debug,
            options: $options,
            corsEnabled: $this->corsEnabled,
            corsOptions: $this->corsOptions,
            subscriptionServer: $subscriptionServer,
            pubSub: $this->pubSub,
            explorer: $this->explorer,
        );
    }
}

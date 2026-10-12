<?php

declare(strict_types=1);

namespace Typic\Server;

use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Typic\Explorer\ExplorerInterface;
use Typic\Explorer\ExplorerType;
use Typic\Middleware\MiddlewareStack;
use Typic\Middleware\ResolverMiddleware;
use Typic\Scalar\ScalarType;
use Typic\Scanner\DirectoryScanner;
use Typic\Schema\Instantiator;
use Typic\Schema\SchemaCompiler;
use Typic\Subscription\Driver\HttpServerInterface;
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
    private ?string $cacheDirectory = null;

    /** @var array<class-string, ScalarType> */
    private array $scalars = [];

    /** @var list<MiddlewareInterface|class-string<MiddlewareInterface>> */
    private array $httpMiddleware = [];

    private ?int $maxDepth = null;
    private ?int $maxComplexity = null;
    private bool $introspection = true;
    private ?int $maxRequestSize = null;
    private ?LoggerInterface $logger = null;
    private bool $csrfProtection = true;
    private bool $sandboxEnabled = true;
    private string $sandboxEndpoint = '/graphql';
    /** @var array<string, mixed> */
    private array $sandboxOptions = [];
    private ?ExplorerInterface $explorer = null;
    private bool $debug = false;
    private ?Cors $cors = null;
    private bool $corsEnabled = true;
    private ?\Typic\Subscription\SubscriptionOptions $subscriptionOptions = null;
    private bool $subscriptionsEnabled = false;
    private ?WebSocketServerInterface $subscriptionDriver = null;
    private ?PubSubInterface $pubSub = null;
    private ?string $subscriptionEndpoint = null;

    /** @var list<ResolverMiddleware|string> */
    private array $resolverMiddleware = [];

    /** @var array<string, list<ResolverMiddleware|string>> */
    private array $middlewareGroups = [];

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
     * Cache what scanDirectories() discovers in $directory, so production does not read every
     * file on each boot (it matters under PHP-FPM, where each request boots). Delete the
     * directory when you deploy new code; leave the cache off while developing.
     *
     * @return $this
     */
    public function withCache(string $directory): self
    {
        $this->cacheDirectory = $directory;
        return $this;
    }

    /**
     * Refuse queries nested deeper than $maxDepth, or with more than $maxComplexity fields
     * (each field counts 1, including nested ones). Applies to subscriptions too.
     *
     * Depth counts from 0 at the root field: in { posts { author { name } } }, posts is 0,
     * author is 1, so the query has depth 1.
     *
     * @return $this
     */
    public function withQueryLimits(?int $maxDepth = null, ?int $maxComplexity = null): self
    {
        $this->maxDepth = $maxDepth;
        $this->maxComplexity = $maxComplexity;
        return $this;
    }

    /**
     * Allow or refuse schema introspection (__schema, __type). Explorers and code generators
     * need it; turn it off in production if your schema is private. __typename keeps working.
     *
     * @return $this
     */
    public function withIntrospection(bool $enabled): self
    {
        $this->introspection = $enabled;
        return $this;
    }

    /**
     * Log unexpected errors (bugs, outages) with their exception, from queries, mutations,
     * subscriptions and HTTP middleware. Errors meant for clients are not logged.
     *
     * @return $this
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Require a GraphQL-Require-Preflight (or Apollo-Require-Preflight) header on POST requests
     * that are not JSON, such as file uploads. On by default: browsers send those requests
     * across sites without a CORS check, so this blocks cross-site request forgery. Upload
     * clients such as apollo-upload-client can be set to send the header.
     *
     * @return $this
     */
    public function withCsrfProtection(bool $enabled): self
    {
        $this->csrfProtection = $enabled;
        return $this;
    }

    /**
     * Answer 413 to request bodies larger than $bytes.
     *
     * @return $this
     */
    public function withMaxRequestSize(?int $bytes): self
    {
        $this->maxRequestSize = $bytes;
        return $this;
    }

    /**
     * PSR-15 middleware around every HTTP request and WebSocket handshake, first outermost.
     * Classes are built with the container. Resolvers read request attributes the middleware
     * sets with #[FromRequest('name')].
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> ...$middleware
     * @return $this
     */
    public function withMiddleware(MiddlewareInterface|string ...$middleware): self
    {
        array_push($this->httpMiddleware, ...array_values($middleware));
        return $this;
    }

    /**
     * Map a PHP type to a custom scalar, for classes you cannot mark with #[Scalar]
     * (e.g. from other packages). Subclasses and implementations of $phpType use it too.
     *
     * @param class-string $phpType
     * @return $this
     */
    public function withScalar(string $phpType, ScalarType $scalar): self
    {
        $this->scalars[$phpType] = $scalar;
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
            $this->explorer = $explorer->createRenderer();
        } else {
            try {
                $this->explorer = ExplorerType::from(strtolower($explorer))->createRenderer();
            } catch (\ValueError) {
                throw new \InvalidArgumentException("Unknown explorer type '{$explorer}'. Available: graphiql, altair, apollo, sandbox.");
            }
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
     * Cross-origin rules for browsers. The default allows any origin without credentials.
     *
     * @param list<string>|string $origins '*', one origin, or a list such as ['https://app.example.com']
     * @param bool $credentials Allow cookies and HTTP auth; needs a list of origins
     * @param string|null $allowHeaders Allowed request headers; defaults to the ones the browser asks for
     * @return $this
     */
    public function withCors(
        bool $enabled = true,
        array|string $origins = '*',
        bool $credentials = false,
        ?string $allowHeaders = null,
        int $maxAge = 86400,
    ): self {
        $this->corsEnabled = $enabled;
        $this->cors = $enabled ? new Cors($origins, $credentials, $allowHeaders, $maxAge) : null;
        return $this;
    }

    /**
     * Run middleware around every resolver method, outside any #[Middleware] on classes and methods.
     *
     * @param ResolverMiddleware|string ...$middleware Instances, classes (built by the container when it
     *                                                 has them), or group names
     * @return $this
     */
    public function withResolverMiddleware(ResolverMiddleware|string ...$middleware): self
    {
        array_push($this->resolverMiddleware, ...array_values($middleware));
        return $this;
    }

    /**
     * Name a stack of middleware, to use as #[Middleware('name')]. The group expands in place,
     * and may include other groups.
     *
     * @param list<ResolverMiddleware|string> $middleware Instances, classes, or group names
     * @return $this
     */
    public function withMiddlewareGroup(string $name, array $middleware): self
    {
        $this->middlewareGroups[$name] = $middleware;
        return $this;
    }

    /**
     * Enable GraphQL real-time subscriptions with a pluggable WebSocket driver and Pub/Sub backplane.
     *
     * When the driver implements HttpServerInterface (WorkermanDriver does), it also serves the
     * HTTP endpoint, so queries, mutations and subscriptions share one event loop and one Pub/Sub.
     * Start it with TypicServer::serve().
     *
     * @param WebSocketServerInterface|null $driver Defaults to WorkermanDriver(port: 8080)
     * @param PubSubInterface|null $pubSub Defaults to RedisPubSub using REDIS_URL, or redis://127.0.0.1:6379.
     *                                     Pass InMemoryPubSub to run a single process without Redis.
     * @param string|null $endpoint WebSocket endpoint URL (e.g. 'ws://localhost:8080'). Defaults to the
     *                              explorer's HTTP endpoint path when the driver also serves HTTP.
     * @return $this
     */
    public function withSubscriptions(
        ?WebSocketServerInterface $driver = null,
        ?PubSubInterface $pubSub = null,
        ?string $endpoint = null,
        ?\Typic\Subscription\SubscriptionOptions $options = null,
    ): self {
        $this->subscriptionOptions = $options;
        $this->subscriptionsEnabled = true;
        $this->subscriptionDriver = $driver;
        $this->pubSub = $pubSub;
        $this->subscriptionEndpoint = $endpoint;
        return $this;
    }

    private function limits(): QueryLimits
    {
        return new QueryLimits($this->maxDepth, $this->maxComplexity, $this->introspection);
    }

    /**
     * @return list<MiddlewareInterface>
     */
    private function createHttpMiddleware(): array
    {
        $instantiator = new Instantiator($this->container);

        return array_map(static function (MiddlewareInterface|string $middleware) use ($instantiator): MiddlewareInterface {
            if ($middleware instanceof MiddlewareInterface) {
                return $middleware;
            }
            $instantiator->check($middleware);

            return $instantiator->create($middleware);
        }, $this->httpMiddleware);
    }

    /**
     * Scan the directories, or read the result from the cache.
     *
     * @return array{resolvers: list<class-string>, types: list<class-string>}
     */
    private function discover(): array
    {
        $file = $this->cacheDirectory !== null ? $this->cacheDirectory . '/discovery.php' : null;
        if ($file !== null && is_file($file)) {
            $cached = require $file;
            if (is_array($cached) && ($cached['directories'] ?? null) === $this->scanDirectories) {
                /** @var array{resolvers: list<class-string>, types: list<class-string>} $cached */
                return ['resolvers' => $cached['resolvers'], 'types' => $cached['types']];
            }
        }

        $scanned = (new DirectoryScanner())->scan($this->scanDirectories);

        if ($file !== null) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            // Write then rename, so a concurrent request never reads a half-written file
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            file_put_contents($tmp, '<?php return ' . var_export(['directories' => $this->scanDirectories] + $scanned, true) . ';' . PHP_EOL);
            rename($tmp, $file);
        }

        return $scanned;
    }

    /**
     * Build the configured TypicServer instance.
     */
    public function build(): TypicServer
    {
        $resolvers = $this->resolvers;
        $types = $this->types;

        if (!empty($this->scanDirectories)) {
            $scanned = $this->discover();

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
            middleware: new MiddlewareStack($this->resolverMiddleware, $this->middlewareGroups, $this->container),
            scalars: $this->scalars,
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
                limits: $this->limits(),
                options: $this->subscriptionOptions,
                maxMessageSize: $this->maxRequestSize,
                errors: new ErrorReporter($this->logger),
            );

            $driver = $subscriptionServer->getDriver();
            $subEndpoint = $this->subscriptionEndpoint
                ?? ($driver instanceof HttpServerInterface
                    ? $this->sandboxEndpoint
                    : 'ws://localhost:' . $driver->getPort());
            $options['subscriptionEndpoint'] ??= $subEndpoint;
            $options['sandbox'] ??= [];
            if (is_array($options['sandbox'])) {
                $options['sandbox']['subscriptionEndpoint'] ??= $subEndpoint;
            }
        }

        $server = new TypicServer(
            schema: $schema,
            container: $this->container,
            sandboxEnabled: $this->sandboxEnabled,
            sandboxEndpoint: $this->sandboxEndpoint,
            debug: $this->debug,
            options: $options,
            cors: $this->corsEnabled ? ($this->cors ?? new Cors()) : null,
            subscriptionServer: $subscriptionServer,
            pubSub: $this->pubSub,
            explorer: $this->explorer,
            middleware: $this->createHttpMiddleware(),
            limits: $this->limits(),
            maxRequestSize: $this->maxRequestSize,
            errors: new ErrorReporter($this->logger),
            csrfProtection: $this->csrfProtection,
        );

        $driver = $subscriptionServer?->getDriver();
        if ($driver instanceof HttpServerInterface) {
            $driver->onRequest($server->handle(...));
            $driver->onHandshake($server->handshake(...));
        }

        return $server;
    }
}

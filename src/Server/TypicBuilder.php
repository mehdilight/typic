<?php

declare(strict_types=1);

namespace Typic\Server;

use Psr\Container\ContainerInterface;
use Typic\Scanner\DirectoryScanner;
use Typic\Schema\SchemaCompiler;

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
    private bool $debug = false;
    private bool $corsEnabled = true;
    /** @var array<string, mixed> */
    private array $corsOptions = [];

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
    public function withSandbox(bool $enabled = true, string $endpoint = '/graphql', array $options = []): self
    {
        $this->sandboxEnabled = $enabled;
        $this->sandboxEndpoint = $endpoint;
        $this->sandboxOptions = $options;
        return $this;
    }

    /**
     * Alias for withSandbox.
     *
     * @param array<string, mixed> $options
     * @return $this
     */
    public function withGraphiQL(bool $enabled = true, string $endpoint = '/graphql', array $options = []): self
    {
        return $this->withSandbox($enabled, $endpoint, $options);
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

        return new TypicServer(
            schema: $schema,
            container: $this->container,
            sandboxEnabled: $this->sandboxEnabled,
            sandboxEndpoint: $this->sandboxEndpoint,
            debug: $this->debug,
            options: $options,
            corsEnabled: $this->corsEnabled,
            corsOptions: $this->corsOptions,
        );
    }
}

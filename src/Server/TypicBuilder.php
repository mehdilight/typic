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
    private bool $graphiqlEnabled = true;
    private string $graphiqlEndpoint = '/graphql';
    private bool $debug = false;

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
     * Enable or disable GraphiQL explorer.
     *
     * @return $this
     */
    public function withGraphiQL(bool $enabled = true, string $endpoint = '/graphql'): self
    {
        $this->graphiqlEnabled = $enabled;
        $this->graphiqlEndpoint = $endpoint;
        return $this;
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

        return new TypicServer(
            schema: $schema,
            container: $this->container,
            graphiqlEnabled: $this->graphiqlEnabled,
            graphiqlEndpoint: $this->graphiqlEndpoint,
            debug: $this->debug,
            options: $this->options,
        );
    }
}

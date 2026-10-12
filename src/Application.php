<?php

declare(strict_types=1);

namespace Typic;

use DI\Container;
use DI\ContainerBuilder;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Typic\DataLoader\DataLoader;
use Typic\Server\TypicBuilder;
use Typic\Server\TypicServer;

/**
 * Bootstraps a standalone Typic application with lazy, autowired services.
 * Configure GraphQL through builder(), then build() for HTTP hosting or serve() for Workerman.
 */
final class Application
{
    private ContainerInterface $container;
    private TypicBuilder $builder;

    /**
     * @param array<string, mixed> $definitions PHP-DI service definitions for the default container
     * @param (callable(class-string<DataLoader<mixed, mixed>>): DataLoader<mixed, mixed>)|null $dataLoaderFactory
     */
    public function __construct(
        array $definitions = [],
        ?ContainerInterface $container = null,
        ?callable $dataLoaderFactory = null,
    ) {
        if ($container !== null && $definitions !== []) {
            throw new InvalidArgumentException('Supply service definitions or an existing container, not both.');
        }
        $this->container = $container ?? (new ContainerBuilder())->addDefinitions($definitions)->build();
        $this->builder = Typic::builder()->withContainer($this->container);

        if ($dataLoaderFactory === null && $this->container instanceof Container) {
            $dataLoaderFactory = $this->container->make(...);
        }
        if ($dataLoaderFactory !== null) {
            $this->builder->withDataLoaderFactory($dataLoaderFactory);
        }
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function builder(): TypicBuilder
    {
        return $this->builder;
    }

    /** Build a server from the current application configuration. */
    public function build(): TypicServer
    {
        return $this->builder->build();
    }

    /** Start the configured HTTP and WebSocket event loop. */
    public function serve(): void
    {
        $this->build()->serve();
    }
}

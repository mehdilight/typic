<?php

declare(strict_types=1);

namespace Typic\Tests;

use DI\Container;
use LogicException;
use PHPUnit\Framework\TestCase;
use Typic\Application;
use Typic\Tests\BuildFixtures\Mailer;
use Typic\Tests\BuildFixtures\NeedsMailerResolver;
use Typic\Tests\Fixtures\Library\Library;
use Typic\Tests\Fixtures\Library\LibraryResolver;

final class ApplicationTest extends TestCase
{
    public function test_application_autowires_resolvers_and_their_services(): void
    {
        $app = new Application();
        $app->builder()->registerResolvers([NeedsMailerResolver::class]);
        $this->assertInstanceOf(Container::class, $app->container());
        $this->assertSame(['data' => ['sender' => 'noreply@example.com']], $app->build()->execute('{ sender }'));
    }

    public function test_service_factories_are_lazy_and_shared(): void
    {
        $calls = 0;
        $app = new Application([
            Mailer::class => static function () use (&$calls): Mailer {
                $calls++;
                return new Mailer();
            },
        ]);
        $app->builder()->registerResolvers([NeedsMailerResolver::class]);
        $server = $app->build();
        $this->assertSame(0, $calls);
        $server->execute('{ sender }');
        $server->execute('{ sender }');
        $this->assertSame(1, $calls);
    }

    public function test_default_container_does_not_share_dataloaders_across_executions(): void
    {
        Library::$queries = [];
        $app = new Application();
        $app->builder()->registerResolvers([LibraryResolver::class]);
        $server = $app->build();
        $query = '{ books { author { name } } }';
        $this->assertArrayNotHasKey('errors', $server->execute($query));
        $this->assertArrayNotHasKey('errors', $server->execute($query));
        $this->assertSame(['authors 1,2,99', 'authors 1,2,99'], Library::$queries);
    }

    public function test_an_existing_psr_container_can_be_supplied(): void
    {
        $container = new class implements \Psr\Container\ContainerInterface {
            public function has(string $id): bool { return $id === Mailer::class; }
            public function get(string $id): object { return new Mailer(); }
        };
        $app = new Application(container: $container);
        $app->builder()->registerResolvers([NeedsMailerResolver::class]);
        $this->assertSame($container, $app->container());
        $this->assertSame(['data' => ['sender' => 'noreply@example.com']], $app->build()->execute('{ sender }'));
    }

    public function test_serve_uses_the_configured_server(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires subscriptions');
        (new Application())->serve();
    }
}

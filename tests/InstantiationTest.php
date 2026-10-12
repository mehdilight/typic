<?php

declare(strict_types=1);

namespace Typic\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Typic\Tests\BuildFixtures\LoaderResolver;
use Typic\Tests\BuildFixtures\Mailer;
use Typic\Tests\BuildFixtures\MailerLoader;
use Typic\Tests\BuildFixtures\NeedsMailerResolver;
use Typic\Tests\BuildFixtures\OptionalMailerResolver;
use Typic\Typic;

class InstantiationTest extends TestCase
{
    public function test_a_missing_resolver_dependency_fails_the_build(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot create ' . NeedsMailerResolver::class . ': constructor parameter $mailer (' . Mailer::class . ') is not in the container.',
        );

        Typic::builder()->registerResolvers([NeedsMailerResolver::class])->build();
    }

    public function test_resolver_dependencies_come_from_the_container(): void
    {
        $server = Typic::builder()
            ->registerResolvers([NeedsMailerResolver::class])
            ->withContainer($this->container([Mailer::class => new Mailer()]))
            ->build();

        $this->assertSame(['data' => ['sender' => 'noreply@example.com']], $server->execute('{ sender }'));
    }

    public function test_optional_dependencies_fall_back_to_null_or_their_default(): void
    {
        $server = Typic::builder()->registerResolvers([OptionalMailerResolver::class])->build();

        $this->assertSame(['data' => ['optionalSender' => 'nobody']], $server->execute('{ optionalSender }'));
    }

    public function test_a_missing_dataloader_dependency_fails_the_build(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot create ' . MailerLoader::class . ': constructor parameter $mailer');

        Typic::builder()->registerResolvers([LoaderResolver::class])->build();
    }

    public function test_dataloader_dependencies_come_from_the_container(): void
    {
        $server = Typic::builder()
            ->registerResolvers([LoaderResolver::class])
            ->withContainer($this->container([Mailer::class => new Mailer()]))
            ->build();

        $this->assertSame(['data' => ['loaded' => 'noreply@example.com']], $server->execute('{ loaded }'));
    }

    public function test_field_method_loader_dependencies_are_checked_at_build_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot create ' . MailerLoader::class);
        Typic::builder()->registerTypes([\Typic\Tests\BuildFixtures\LoadedType::class])->build();
    }

    /**
     * @param array<string, object> $entries
     */
    private function container(array $entries): ContainerInterface
    {
        return new class ($entries) implements ContainerInterface {
            /**
             * @param array<string, object> $entries
             */
            public function __construct(private array $entries)
            {
            }

            public function get(string $id): object
            {
                return $this->entries[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->entries[$id]);
            }
        };
    }
}

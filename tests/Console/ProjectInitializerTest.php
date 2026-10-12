<?php

declare(strict_types=1);

namespace Typic\Tests\Console;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Typic\Console\ProjectInitializer;

final class ProjectInitializerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/typic-init-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_generates_a_standalone_project_and_local_repository_configuration(): void
    {
        $initializer = new ProjectInitializer();
        $initializer->create($this->directory, 'acme/my-api', 'dev-main', dirname(__DIR__, 2));
        $composer = json_decode((string) file_get_contents($this->directory . '/composer.json'), true);
        $this->assertSame('acme/my-api', $composer['name']);
        $this->assertSame('dev-main', $composer['require']['phpmystic/typic']);
        $this->assertSame(['App\\' => 'src/'], $composer['autoload']['psr-4']);
        $this->assertSame('path', $composer['repositories'][0]['type']);
        foreach (['bootstrap.php', 'bin/server.php', 'bin/export-schema.php', 'src/GraphQL/Resolvers/GreetingResolver.php', 'src/Services/GreetingService.php', 'README.md', '.gitignore', 'tests/ApiTest.php'] as $file) {
            $this->assertFileExists($this->directory . '/' . $file);
        }
    }

    public function test_non_empty_directories_are_never_overwritten(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/.keep', 'original');
        try {
            (new ProjectInitializer())->create($this->directory, 'acme/api', 'dev-main');
            $this->fail('Expected refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not empty', $e->getMessage());
            $this->assertSame('original', file_get_contents($this->directory . '/.keep'));
        }
    }

    public function test_existing_empty_directories_are_supported(): void
    {
        mkdir($this->directory);
        (new ProjectInitializer())->create($this->directory, 'acme/api', 'dev-main');
        $this->assertFileExists($this->directory . '/composer.json');
    }

    public function test_invalid_package_names_fail_before_creating_files(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProjectInitializer())->create($this->directory, 'Invalid Name', 'dev-main');
    }
}

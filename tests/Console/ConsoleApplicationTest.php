<?php

declare(strict_types=1);

namespace Typic\Tests\Console;

use PHPUnit\Framework\TestCase;

final class ConsoleApplicationTest extends TestCase
{
    /** @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function command(array $arguments): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/typic', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $output, (string) $error];
    }

    public function test_help_and_unknown_commands_have_useful_exit_codes(): void
    {
        [$code, $output] = $this->command(['--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Usage:', $output);
        [$code, $output] = $this->command(['init', '--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('--framework-version', $output);
        [$code, $output] = $this->command(['list', '--raw']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('init', $output);
        $this->assertStringContainsString('completion', $output);
        [$code, , $error] = $this->command(['unknown']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('is not defined', $error);
    }

    public function test_missing_arguments_and_unknown_options_fail_before_writing(): void
    {
        foreach ([['init'], ['init', '--name'], ['init', '--force']] as $arguments) {
            [$code, , $error] = $this->command($arguments);
            $this->assertSame(1, $code);
            $this->assertNotSame('', $error);
        }
    }

    public function test_cli_generates_a_project_without_installing_dependencies(): void
    {
        $directory = sys_get_temp_dir() . '/typic-cli-' . bin2hex(random_bytes(8));
        try {
            [$code, $output, $error] = $this->command(['init', $directory, '--no-install', '--name', 'acme/api', '--framework-version=dev-main', '--repository=' . dirname(__DIR__, 2)]);
            $this->assertSame(0, $code, $error);
            $this->assertStringContainsString('Created acme/api', $output);
            $this->assertFileExists($directory . '/composer.json');
            $this->assertDirectoryDoesNotExist($directory . '/vendor');
            [$code, , $error] = $this->command(['init', $directory, '--no-install']);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('not empty', $error);
        } finally {
            if (is_dir($directory)) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($directory);
            }
        }
    }
}

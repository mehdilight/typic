<?php

declare(strict_types=1);

namespace Typic\Console;

use InvalidArgumentException;
use RuntimeException;

/** Writes the standalone project skeleton without replacing existing files. */
final class ProjectInitializer
{
    public function create(string $directory, string $name, string $version, ?string $repository = null): void
    {
        if (preg_match('~^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$~D', $name) !== 1) {
            throw new InvalidArgumentException('The package name must look like vendor/project using lowercase letters, numbers, dots, underscores or dashes.');
        }
        if ($version === '') {
            throw new InvalidArgumentException('The framework version cannot be empty.');
        }
        if (file_exists($directory) && !is_dir($directory)) {
            throw new RuntimeException("Destination is not a directory: {$directory}");
        }
        if (is_dir($directory) && (new \FilesystemIterator($directory))->valid()) {
            throw new RuntimeException("Destination is not empty: {$directory}. Choose a new or empty directory.");
        }
        if (is_link($directory)) {
            throw new RuntimeException('The destination must not be a symbolic link.');
        }

        $composer = [
            'name' => $name,
            'description' => 'A standalone Typic GraphQL application',
            'type' => 'project',
            'license' => 'proprietary',
            'require' => ['php' => '>=8.2', 'ext-pcntl' => '*', 'ext-posix' => '*', 'phpmystic/typic' => $version],
            'require-dev' => ['phpunit/phpunit' => '^10.5 || ^11.0', 'phpstan/phpstan' => '^2.0'],
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/']],
            'scripts' => [
                'start' => 'php bin/server.php start', 'dev' => '@start', 'stop' => 'php bin/server.php stop',
                'export:schema' => 'php bin/export-schema.php', 'test' => 'phpunit',
                'phpstan' => 'phpstan analyse --memory-limit=1G',
            ],
            'config' => ['process-timeout' => 0, 'sort-packages' => true, 'allow-plugins' => (object) []],
            'minimum-stability' => 'stable', 'prefer-stable' => true,
        ];
        if ($repository !== null) {
            $path = realpath($repository);
            if ($path !== false && is_dir($path)) {
                $composer['repositories'] = [['type' => 'path', 'url' => $path, 'options' => ['symlink' => true]]];
            } elseif (preg_match('~^(https?://|ssh://|git@)~', $repository) === 1) {
                $composer['repositories'] = [['type' => 'vcs', 'url' => $repository]];
            } else {
                throw new InvalidArgumentException('Repository must be an existing directory or a Git repository URL.');
            }
        }

        $templateDirectory = dirname(__DIR__, 2) . '/resources/project';
        $files = ['composer.json' => json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"];
        $templates = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templateDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($templates as $template) {
            if (!$template->isFile()) {
                continue;
            }
            $content = file_get_contents($template->getPathname());
            if ($content === false) {
                throw new RuntimeException('Cannot read project template: ' . $template->getPathname());
            }
            $relative = substr($template->getPathname(), strlen($templateDirectory) + 1);
            $relative = preg_replace('/\.stub$/', '', $relative);
            if ($relative === null) {
                throw new RuntimeException('Cannot determine template filename.');
            }
            $files[$relative] = str_replace('{{package_name}}', $name, $content);
        }
        foreach ($files as $relative => $content) {
            $path = $directory . '/' . $relative;
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
                throw new RuntimeException('Cannot create directory: ' . dirname($path));
            }
            // Exclusive creation protects existing files, even if another process writes during init.
            $stream = @fopen($path, 'x');
            if ($stream === false) {
                throw new RuntimeException("Cannot create file: {$path}");
            }
            try {
                if (fwrite($stream, $content) !== strlen($content)) {
                    throw new RuntimeException("Cannot write file: {$path}");
                }
            } finally {
                fclose($stream);
            }
        }
    }
}

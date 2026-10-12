<?php

declare(strict_types=1);

namespace Typic\Console;

use Composer\InstalledVersions;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'init', description: 'Create a standalone Typic GraphQL project')]
final class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'A new or empty project directory')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Composer project name (default: app/<directory>)')
            ->addOption('framework-version', null, InputOption::VALUE_REQUIRED, 'Framework constraint (default: installed Typic version)')
            ->addOption('repository', null, InputOption::VALUE_REQUIRED, 'Local framework checkout or Git repository URL')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'Generate files without installing Composer dependencies')
            ->setHelp('Creates the project skeleton and runs Composer install. Example: <info>typic init my-api --name=acme/my-api</info>');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = (string) $input->getArgument('directory');
        $name = $input->getOption('name') ?? 'app/' . strtolower(basename(rtrim($directory, '/\\')));
        $version = InstalledVersions::getPrettyVersion('phpmystic/typic') ?? 'dev-main';
        $constraint = preg_match('/^v?\d+\.\d+\.\d+$/D', $version) === 1 ? '^' . ltrim($version, 'v') : $version;

        (new ProjectInitializer())->create(
            $directory,
            (string) $name,
            (string) ($input->getOption('framework-version') ?? $constraint),
            $input->getOption('repository'),
        );
        $absolute = realpath($directory);
        if ($absolute === false) {
            throw new RuntimeException('Cannot locate the generated project.');
        }
        $io->success(OutputFormatter::escape("Created {$name} in {$absolute}"));

        if (!$input->getOption('no-install')) {
            $io->text('Installing Composer dependencies...');
            $process = new Process(['composer', 'install', '--no-interaction'], $absolute);
            $process->setTimeout(null);
            $status = $process->run(static function (string $type, string $buffer) use ($output): void {
                $stream = $type === Process::ERR && $output instanceof ConsoleOutputInterface
                    ? $output->getErrorOutput() : $output;
                $stream->write($buffer, false, OutputInterface::OUTPUT_RAW);
            });
            if ($status !== 0) {
                $io->error(OutputFormatter::escape("Composer installation failed. Project files are kept in {$absolute}. Fix the dependency error and run composer install there."));
                return Command::FAILURE;
            }
        }

        $io->section('Next steps');
        $steps = [OutputFormatter::escape("Open {$absolute}")];
        if ($input->getOption('no-install')) {
            $steps[] = 'Run composer install';
        }
        $steps[] = 'Run composer start';
        $steps[] = 'Open http://localhost:8000/graphql';
        $io->listing($steps);
        $io->note('Redis is used for mutations and subscriptions; configure REDIS_URL if needed.');
        return Command::SUCCESS;
    }
}

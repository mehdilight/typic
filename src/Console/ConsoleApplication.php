<?php

declare(strict_types=1);

namespace Typic\Console;

use Composer\InstalledVersions;
use Symfony\Component\Console\Application;

/** The standalone CLI. Register additional Symfony commands with addCommand(). */
final class ConsoleApplication extends Application
{
    public function __construct()
    {
        parent::__construct('Typic', InstalledVersions::getPrettyVersion('phpmystic/typic') ?? 'dev-main');
        $this->addCommand(new InitCommand());
    }
}

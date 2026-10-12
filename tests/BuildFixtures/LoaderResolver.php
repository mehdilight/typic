<?php

declare(strict_types=1);

namespace Typic\Tests\BuildFixtures;

use Typic\Attribute\Query;

class LoaderResolver
{
    #[Query]
    public function loaded(MailerLoader $loader): string
    {
        return $loader->get(1);
    }
}

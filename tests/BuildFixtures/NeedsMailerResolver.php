<?php

declare(strict_types=1);

namespace Typic\Tests\BuildFixtures;

use Typic\Attribute\Query;

class NeedsMailerResolver
{
    public function __construct(private Mailer $mailer)
    {
    }

    #[Query]
    public function sender(): string
    {
        return $this->mailer->from();
    }
}

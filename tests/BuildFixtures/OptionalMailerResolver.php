<?php

declare(strict_types=1);

namespace Typic\Tests\BuildFixtures;

use Typic\Attribute\Query;

class OptionalMailerResolver
{
    public function __construct(private ?Mailer $mailer, private string $fallback = 'nobody')
    {
    }

    #[Query]
    public function optionalSender(): string
    {
        return $this->mailer?->from() ?? $this->fallback;
    }
}

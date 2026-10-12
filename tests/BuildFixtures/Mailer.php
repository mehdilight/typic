<?php

declare(strict_types=1);

namespace Typic\Tests\BuildFixtures;

final class Mailer
{
    public function from(): string
    {
        return 'noreply@example.com';
    }
}

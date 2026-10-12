<?php

declare(strict_types=1);

namespace Typic\Tests\BuildFixtures;

use Typic\DataLoader\DataLoader;

/**
 * @extends DataLoader<int, string>
 */
final class MailerLoader extends DataLoader
{
    public function __construct(private Mailer $mailer)
    {
        parent::__construct();
    }

    protected function batch(array $keys): iterable
    {
        return array_fill_keys($keys, $this->mailer->from());
    }
}

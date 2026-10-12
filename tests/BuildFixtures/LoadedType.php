<?php

declare(strict_types=1);

namespace Typic\Tests\BuildFixtures;

use Typic\Attribute\Field;
use Typic\Attribute\ObjectType;

#[ObjectType]
final class LoadedType
{
    #[Field]
    public function sender(MailerLoader $loader): string
    {
        return $loader->get(1);
    }
}

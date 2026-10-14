<?php

declare(strict_types=1);

namespace Typic\Tests\ValidationFixtures;

use Typic\Attribute\Arg;
use Typic\Validation as Assert;

/** Resolver-style arguments for checking how a field's rules run together. */
final class HandleRules
{
    public function check(
        #[Arg] #[Assert\NotBlank] #[Assert\Length(min: 3)] #[Assert\Pattern('/^[a-z-]+$/')] ?string $all = null,
        #[Arg] #[Assert\Bail] #[Assert\Length(min: 3)] #[Assert\Pattern('/^[a-z-]+$/')] ?string $bail = null,
        #[Arg] #[Assert\Length(min: 3)] #[Assert\Email] ?string $optional = null,
    ): void {
    }
}

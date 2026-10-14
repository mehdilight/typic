<?php

declare(strict_types=1);

namespace Typic\Tests\PresenceFixtures;

use Typic\Attribute\Field;
use Typic\Attribute\InputType;
use Typic\Input\PresenceAware;
use Typic\Input\TracksPresence;

#[InputType]
final class ProfilePatch implements PresenceAware
{
    use TracksPresence;

    public function __construct(
        #[Field(nullable: true)] public ?string $name,
        #[Field(nullable: true)] public ?string $bio,
        #[Field(name: 'website', nullable: true)] public ?string $homepage,
        #[Field(nullable: true)] public ?AddressPatch $address,
    ) {
    }
}

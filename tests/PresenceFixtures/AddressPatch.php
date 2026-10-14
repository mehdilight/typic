<?php

declare(strict_types=1);

namespace Typic\Tests\PresenceFixtures;

use Typic\Attribute\Field;
use Typic\Attribute\InputType;
use Typic\Input\PresenceAware;
use Typic\Input\TracksPresence;

#[InputType]
final class AddressPatch implements PresenceAware
{
    use TracksPresence;

    public function __construct(
        #[Field(nullable: true)] public ?string $city,
        #[Field(nullable: true)] public ?string $country,
    ) {
    }
}

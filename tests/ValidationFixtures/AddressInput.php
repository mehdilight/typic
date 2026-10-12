<?php

declare(strict_types=1);

namespace Typic\Tests\ValidationFixtures;

use Typic\Attribute\InputType;
use Typic\Validation as Assert;

#[InputType]
final readonly class AddressInput
{
    public function __construct(
        #[Assert\NotBlank] public string $city,
        #[Assert\Length(min: 2, max: 2, message: 'must be a 2-letter country code')] public string $country,
    ) {
    }
}

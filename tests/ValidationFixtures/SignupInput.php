<?php

declare(strict_types=1);

namespace Typic\Tests\ValidationFixtures;

use Typic\Attribute\Field;
use Typic\Attribute\InputType;
use Typic\Validation as Assert;

#[InputType]
final readonly class SignupInput
{
    /**
     * @param list<string> $tags
     * @param list<AddressInput> $previousAddresses
     */
    public function __construct(
        #[Assert\NotBlank] #[Assert\Length(max: 20)] #[NotReserved] public string $username,
        #[Assert\Email] public string $email,
        #[Assert\Range(min: 13)] public int $age,
        #[Field(name: 'plan')] #[Assert\OneOf(['free', 'pro'])] public string $tier = 'free',
        #[Assert\Url] public ?string $website = null,
        public ?AddressInput $address = null,
        #[Field(arrayOf: 'string')] #[Assert\Length(max: 3)] public array $tags = [],
        #[Field(arrayOf: AddressInput::class)] public array $previousAddresses = [],
        #[Assert\Pattern('/^\+?[0-9 ]+$/', message: 'must contain only digits and spaces')] public ?string $phone = null,
    ) {
    }
}

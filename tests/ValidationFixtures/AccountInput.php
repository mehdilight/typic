<?php

declare(strict_types=1);

namespace Typic\Tests\ValidationFixtures;

use DateTimeImmutable;
use Typic\Attribute\Field;
use Typic\Attribute\InputType;
use Typic\Validation as Assert;

#[InputType]
final readonly class AccountInput
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[Assert\Length(min: 8)] public string $password,
        #[Assert\Same('password', message: 'must match the password')] public string $passwordConfirmation,
        #[Assert\OneOf(['EMAIL', 'SMS'])] public string $notify,
        #[Assert\RequiredIf('notify', 'EMAIL')] #[Assert\Email] public ?string $email = null,
        #[Assert\RequiredIf('notify', 'SMS')] public ?string $phone = null,
        #[Assert\NotBlank] public ?string $displayName = null,
        #[Field(arrayOf: 'string')] #[Assert\Distinct(ignoreCase: true)] #[Assert\Each(new Assert\Length(max: 5), new Assert\AlphaDash())] public array $tags = [],
        public ?DateTimeImmutable $trialStartsAt = null,
        #[Assert\After('trialStartsAt')] public ?DateTimeImmutable $trialEndsAt = null,
    ) {
    }
}

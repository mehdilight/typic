<?php

declare(strict_types=1);

namespace Typic\Tests\ScalarFixtures;

use DateTimeImmutable;
use Typic\Attribute\InputType;

#[InputType]
final readonly class EventInput
{
    public function __construct(
        public string $title,
        public DateTimeImmutable $startsAt,
        public ?Email $contact = null,
    ) {
    }
}

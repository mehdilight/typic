<?php

declare(strict_types=1);

namespace Typic\Tests\ScalarFixtures;

use DateTimeInterface;
use Typic\Attribute\ObjectType;

#[ObjectType]
final readonly class Event
{
    public function __construct(
        public string $title,
        public DateTimeInterface $startsAt,
        public ?Email $contact,
        public Uuid $id,
    ) {
    }
}

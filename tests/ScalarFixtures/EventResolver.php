<?php

declare(strict_types=1);

namespace Typic\Tests\ScalarFixtures;

use DateTime;
use DateTimeImmutable;
use Typic\Attribute\Mutation;
use Typic\Attribute\Query;

class EventResolver
{
    public static int $calls = 0;

    #[Query]
    public function launch(): Event
    {
        return new Event(
            'Launch',
            new DateTimeImmutable('2026-10-12 10:00:00', new \DateTimeZone('UTC')),
            Email::parse('team@example.com'),
            new Uuid('123e4567-e89b-12d3-a456-426614174000'),
        );
    }

    #[Query]
    public function later(DateTimeImmutable $at, int $hours): DateTimeImmutable
    {
        self::$calls++;

        return $at->modify("+{$hours} hours");
    }

    #[Query]
    public function mutableLater(DateTime $at): DateTime
    {
        return $at->modify('+1 day');
    }

    #[Query]
    public function echoEmail(Email $email): Email
    {
        return $email;
    }

    #[Query]
    public function echoUuid(Uuid $id): Uuid
    {
        return $id;
    }

    #[Mutation]
    public function createEvent(EventInput $input): Event
    {
        return new Event($input->title, $input->startsAt, $input->contact, new Uuid('00000000-0000-0000-0000-000000000001'));
    }
}

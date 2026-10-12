<?php

declare(strict_types=1);

namespace Typic\Tests;

use PHPUnit\Framework\TestCase;
use Typic\Server\TypicServer;
use Typic\Tests\ScalarFixtures\Event;
use Typic\Tests\ScalarFixtures\EventResolver;
use Typic\Tests\ScalarFixtures\Uuid;
use Typic\Tests\ScalarFixtures\UuidScalar;
use Typic\Typic;

class ScalarTest extends TestCase
{
    protected function setUp(): void
    {
        EventResolver::$calls = 0;
    }

    public function test_scalars_are_serialized(): void
    {
        $result = $this->server()->execute('{ launch { title startsAt contact id } }');

        $this->assertSame(['data' => ['launch' => [
            'title' => 'Launch',
            'startsAt' => '2026-10-12T10:00:00+00:00',
            'contact' => 'team@example.com',
            'id' => '123e4567-e89b-12d3-a456-426614174000',
        ]]], $result);
    }

    public function test_date_time_arguments_from_literals_and_variables(): void
    {
        $server = $this->server();

        $literal = $server->execute('{ later(at: "2026-10-12T10:00:00+02:00", hours: 3) }');
        $this->assertSame(['data' => ['later' => '2026-10-12T13:00:00+02:00']], $literal);

        $variable = $server->execute('query ($at: DateTime!) { later(at: $at, hours: 1) }', ['at' => '2026-01-01T00:00:00.500Z']);
        $this->assertSame(['data' => ['later' => '2026-01-01T01:00:00+00:00']], $variable);

        $mutable = $server->execute('{ mutableLater(at: "2026-10-12T10:00:00+00:00") }');
        $this->assertSame(['data' => ['mutableLater' => '2026-10-13T10:00:00+00:00']], $mutable);
    }

    public function test_invalid_scalar_input_is_refused_before_the_resolver_runs(): void
    {
        $server = $this->server();

        $date = $server->execute('{ later(at: "yesterday", hours: 1) }');
        $this->assertStringContainsString(
            'DateTime must be an ISO 8601 date and time with a time zone, like 2026-10-12T10:00:00+00:00',
            $date['errors'][0]['message'],
        );

        $email = $server->execute('query ($e: Email!) { echoEmail(email: $e) }', ['e' => 'nope']);
        $this->assertStringContainsString('Not a valid email address: nope', $email['errors'][0]['message']);

        $this->assertSame(0, EventResolver::$calls);
    }

    public function test_value_object_and_mapped_scalars_round_trip(): void
    {
        $result = $this->server()->execute('{ echoEmail(email: "Ana@Example.com") echoUuid(id: "123E4567-E89B-12D3-A456-426614174000") }');

        $this->assertSame(['data' => [
            'echoEmail' => 'ana@example.com',
            'echoUuid' => '123e4567-e89b-12d3-a456-426614174000',
        ]], $result);
    }

    public function test_scalars_in_input_objects(): void
    {
        $result = $this->server()->execute(
            'mutation ($input: EventInput!) { createEvent(input: $input) { title startsAt contact } }',
            ['input' => ['title' => 'Party', 'startsAt' => '2026-12-31T22:00:00+01:00', 'contact' => 'host@example.com']],
        );

        $this->assertSame(['data' => ['createEvent' => [
            'title' => 'Party',
            'startsAt' => '2026-12-31T22:00:00+01:00',
            'contact' => 'host@example.com',
        ]]], $result);
    }

    public function test_scalars_are_described_in_the_schema(): void
    {
        $result = $this->server()->execute('{
            dateTime: __type(name: "DateTime") { kind description }
            email: __type(name: "Email") { kind description }
            uuid: __type(name: "UUID") { kind description }
        }');

        $this->assertSame('SCALAR', $result['data']['dateTime']['kind']);
        $this->assertStringContainsString('ISO 8601', $result['data']['dateTime']['description']);
        $this->assertSame(['kind' => 'SCALAR', 'description' => 'An email address'], $result['data']['email']);
        $this->assertSame(['kind' => 'SCALAR', 'description' => 'A UUID such as 123e4567-e89b-12d3-a456-426614174000'], $result['data']['uuid']);
    }

    private function server(): TypicServer
    {
        return Typic::builder()
            ->registerResolvers([EventResolver::class])
            ->registerTypes([Event::class])
            ->withScalar(Uuid::class, new UuidScalar())
            ->build();
    }
    public function test_datetime_rejects_invalid_calendar_dates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new \Typic\Scalar\DateTimeScalar())->parse('2026-02-30T10:00:00+00:00');
    }

}

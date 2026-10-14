<?php

declare(strict_types=1);

namespace Typic\Tests;

use PHPUnit\Framework\TestCase;
use Typic\Tests\PresenceFixtures\AddressPatch;
use Typic\Tests\PresenceFixtures\ProfilePatch;
use Typic\Tests\PresenceFixtures\ProfileResolver;
use Typic\Typic;

final class PresenceTest extends TestCase
{
    public function test_inputs_tell_omitted_fields_from_null_ones(): void
    {
        $server = Typic::builder()
            ->registerResolvers([ProfileResolver::class])
            ->registerTypes([ProfilePatch::class, AddressPatch::class])
            ->build();
        $mutation = 'mutation ($input: ProfilePatch!) { patchProfile(input: $input) }';

        $result = $server->execute($mutation, ['input' => ['name' => 'Mehdi', 'bio' => null, 'address' => ['country' => null]]]);
        $this->assertSame(['data' => ['patchProfile' => 'name=Mehdi bio=null homepage=omitted address=country']], $result);

        $renamed = $server->execute($mutation, ['input' => ['website' => 'https://example.com']]);
        $this->assertSame(['data' => ['patchProfile' => 'name=omitted bio=omitted homepage=https://example.com address=-']], $renamed);
    }

    public function test_filled_rejects_a_sent_empty_value_but_allows_leaving_the_field_out(): void
    {
        $server = Typic::builder()
            ->registerResolvers([ProfileResolver::class])
            ->registerTypes([ProfilePatch::class, AddressPatch::class])
            ->build();
        $mutation = 'mutation ($input: ProfilePatch!) { patchProfile(input: $input) }';

        $this->assertSame(['data' => ['patchProfile' => 'name=omitted bio=null homepage=omitted address=-']], $server->execute($mutation, ['input' => ['bio' => null]]));
        foreach ([null, '  '] as $empty) {
            $result = $server->execute($mutation, ['input' => ['name' => $empty]]);
            $this->assertSame([['path' => 'input.name', 'code' => 'BLANK', 'message' => 'must not be empty', 'params' => []]], json_decode((string) json_encode($result['errors'][0]['extensions']['violations']), true));
        }
    }
}

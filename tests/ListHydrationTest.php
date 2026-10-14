<?php

declare(strict_types=1);

namespace Typic\Tests;

use PHPUnit\Framework\TestCase;
use Typic\Tests\ListFixtures\Color;
use Typic\Tests\ListFixtures\LineInput;
use Typic\Tests\ListFixtures\OrderInput;
use Typic\Tests\ListFixtures\OrderResolver;
use Typic\Typic;

final class ListHydrationTest extends TestCase
{
    public function test_lists_of_enums_and_input_objects_are_hydrated_in_inputs_and_arguments(): void
    {
        $server = Typic::builder()
            ->registerResolvers([OrderResolver::class])
            ->registerTypes([Color::class, LineInput::class, OrderInput::class])
            ->build();

        $this->assertSame(['data' => ['paint' => 'RED,BLUE']], $server->execute('{ paint(colors: [RED, BLUE]) }'));
        $result = $server->execute(
            'mutation ($input: OrderInput!, $extra: [LineInput!]) { order(input: $input, extra: $extra) }',
            ['input' => ['colors' => ['BLUE'], 'lines' => [['sku' => 'A', 'quantity' => 2]]], 'extra' => [['sku' => 'B', 'quantity' => 1]]],
        );
        $this->assertSame(['data' => ['order' => 'BLUE 2xA,1xB']], $result);
    }
}

<?php

declare(strict_types=1);

namespace Typic\Tests\ListFixtures;

use Typic\Attribute\Arg;
use Typic\Attribute\Mutation;
use Typic\Attribute\Query;

final class OrderResolver
{
    /** @param list<Color> $colors */
    #[Query]
    public function paint(#[Arg(arrayOf: Color::class)] array $colors): string
    {
        return implode(',', array_map(static fn (Color $color): string => $color->name, $colors));
    }

    /** @param list<LineInput> $extra */
    #[Mutation]
    public function order(OrderInput $input, #[Arg(arrayOf: LineInput::class)] array $extra = []): string
    {
        $lines = array_map(static fn (LineInput $line): string => "{$line->quantity}x{$line->sku}", [...$input->lines, ...$extra]);

        return implode(',', array_map(static fn (Color $color): string => $color->value, $input->colors)) . ' ' . implode(',', $lines);
    }
}

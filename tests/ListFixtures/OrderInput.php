<?php

declare(strict_types=1);

namespace Typic\Tests\ListFixtures;

use Typic\Attribute\Field;
use Typic\Attribute\InputType;

#[InputType]
final class OrderInput
{
    /**
     * @param list<Color> $colors
     * @param list<LineInput> $lines
     */
    public function __construct(
        #[Field(arrayOf: Color::class)] public array $colors,
        #[Field(arrayOf: LineInput::class)] public array $lines,
    ) {
    }
}

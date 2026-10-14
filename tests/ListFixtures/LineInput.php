<?php

declare(strict_types=1);

namespace Typic\Tests\ListFixtures;

use Typic\Attribute\Field;
use Typic\Attribute\InputType;

#[InputType]
final class LineInput
{
    public function __construct(
        #[Field] public string $sku,
        #[Field] public int $quantity,
    ) {
    }
}

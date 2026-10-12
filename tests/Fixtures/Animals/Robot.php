<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Animals;

use Typic\Attribute\Field;
use Typic\Attribute\ObjectType;

#[ObjectType]
final class Robot implements Named
{
    public function __construct(
        #[Field] public int $model,
    ) {
    }

    public function name(): string
    {
        return "RX-{$this->model}";
    }
}

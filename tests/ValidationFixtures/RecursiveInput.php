<?php

declare(strict_types=1);

namespace Typic\Tests\ValidationFixtures;

use Typic\Attribute\InputType;
use Typic\Validation\NotBlank;

#[InputType]
final readonly class RecursiveInput
{
    public function __construct(#[NotBlank] public string $name, public ?RecursiveInput $child = null)
    {
    }
}

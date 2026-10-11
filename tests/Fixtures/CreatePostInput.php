<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures;

use Typic\Attribute\Field;
use Typic\Attribute\InputType;

#[InputType(name: 'CreatePostInput')]
readonly class CreatePostInput
{
    public function __construct(
        #[Field] public string $title,
        #[Field] public string $content,
        #[Field] public ?PostMetadataInput $metadata = null,
        #[Field] public StatusEnum $status = StatusEnum::PUBLISHED,
    ) {
    }
}

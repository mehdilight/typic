<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures;

use Typic\Attribute\Field;
use Typic\Attribute\InputType;

#[InputType(name: 'PostMetadataInput')]
readonly class PostMetadataInput
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[Field] public ?string $seoDescription = null,
        #[Field(arrayOf: 'string')] public array $tags = [],
    ) {
    }
}

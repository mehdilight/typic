<?php

declare(strict_types=1);

namespace Typic\Tests\UploadFixtures;

use Psr\Http\Message\UploadedFileInterface;
use Typic\Attribute\InputType;

#[InputType]
final readonly class ProfileInput
{
    public function __construct(
        public string $name,
        public ?UploadedFileInterface $avatar = null,
    ) {
    }
}

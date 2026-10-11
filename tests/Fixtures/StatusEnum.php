<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures;

use Typic\Attribute\EnumType;

#[EnumType(name: 'PostStatus', description: 'Publication status of a post')]
enum StatusEnum: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case ARCHIVED = 'ARCHIVED';
}

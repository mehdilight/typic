<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures;

use Typic\Attribute\Field;
use Typic\Attribute\ID;
use Typic\Attribute\ObjectType;

#[ObjectType(name: 'Post', description: 'A blog post')]
class Post
{
    #[Field]
    #[ID]
    public int $id;

    #[Field]
    public string $title;

    #[Field]
    public ?string $content;

    #[Field]
    public StatusEnum $status;

    public int $authorId;

    public function __construct(
        int $id,
        string $title,
        ?string $content,
        int $authorId,
        StatusEnum $status = StatusEnum::PUBLISHED,
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->content = $content;
        $this->authorId = $authorId;
        $this->status = $status;
    }

    #[Field]
    public function snippet(): string
    {
        return mb_substr($this->content ?? '', 0, 10) . '...';
    }
}

<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures;

use Typic\Attribute\ResolveField;

class PostFieldResolver
{
    #[ResolveField(target: Post::class)]
    public function author(Post $post, UserDataLoader $users): ?User
    {
        return $users->get($post->authorId);
    }
}

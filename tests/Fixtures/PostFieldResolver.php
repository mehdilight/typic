<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures;

use GraphQL\Deferred;
use Typic\Attribute\ResolveField;

class PostFieldResolver
{
    /**
     * Resolves the author of a Post using DataLoader.
     *
     * @return Deferred
     */
    #[ResolveField(target: Post::class, name: 'author', type: User::class)]
    public function author(Post $post, UserDataLoader $userLoader): Deferred
    {
        return $userLoader->load($post->authorId);
    }
}

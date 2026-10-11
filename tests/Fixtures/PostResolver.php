<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures;

use Typic\Attribute\Arg;
use Typic\Attribute\ID;
use Typic\Attribute\Mutation;
use Typic\Attribute\Query;
use Typic\Pagination\Connection;
use Typic\Pagination\Paginator;

class PostResolver
{
    /**
     * @var list<Post>
     */
    private array $posts;

    public function __construct()
    {
        $this->posts = [
            new Post(1, 'First Post', 'Hello world content', 1, StatusEnum::PUBLISHED),
            new Post(2, 'Second Post', 'Second post description', 2, StatusEnum::DRAFT),
            new Post(3, 'Third Post', 'Third post details', 1, StatusEnum::PUBLISHED),
        ];
    }

    /**
     * @return list<Post>
     */
    #[Query(arrayOf: Post::class)]
    public function posts(?StatusEnum $status = null): array
    {
        if ($status !== null) {
            return array_values(array_filter($this->posts, fn ($p) => $p->status === $status));
        }

        return $this->posts;
    }

    #[Query]
    public function post(#[ID] int $id): ?Post
    {
        foreach ($this->posts as $p) {
            if ($p->id === $id) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @return Paginator<Post>
     */
    #[Query(arrayOf: Post::class)]
    public function paginatedPosts(
        #[Arg(defaultValue: 1)] int $page = 1,
        #[Arg(defaultValue: 2)] int $perPage = 2,
    ): Paginator {
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($this->posts, $offset, $perPage);

        return Paginator::from(
            items: $slice,
            currentPage: $page,
            perPage: $perPage,
            total: count($this->posts),
        );
    }

    /**
     * @return Connection<Post>
     */
    #[Query(arrayOf: Post::class)]
    public function postsConnection(#[Arg(defaultValue: 2)] int $first = 2): Connection
    {
        $items = array_slice($this->posts, 0, $first);

        return Connection::from(
            items: $items,
            hasNextPage: count($this->posts) > $first,
            hasPreviousPage: false,
            totalCount: count($this->posts),
        );
    }

    #[Mutation]
    public function createPost(CreatePostInput $input): Post
    {
        $newPost = new Post(
            id: count($this->posts) + 1,
            title: $input->title,
            content: $input->content,
            authorId: 1,
            status: $input->status,
        );

        $this->posts[] = $newPost;

        return $newPost;
    }
}

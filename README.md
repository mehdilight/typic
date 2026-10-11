# Typic

> **Modern, Code-First, Attribute-Driven GraphQL Framework for PHP 8.2+**

[![Tests](https://img.shields.io/badge/tests-passing-brightgreen.svg)]()
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208%20clean-brightgreen.svg)]()
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)]()
[![License](https://img.shields.io/badge/license-MIT-blue.svg)]()

**Typic** is a lightweight, high-performance PHP framework dedicated specifically to building GraphQL APIs. It eliminates manual SDL schema strings (`typeDefs`) entirely in favor of native **PHP 8 attributes**, reflection, and strong types.

---

## Features

- **Zero SDL Boilerplate**: Define your schema using pure PHP classes and attributes. Types, fields, nullability, and arguments are inferred automatically.
- **Real-Time Subscriptions**: Driver-based WebSocket subscription engine powered by Workerman, supporting standard graphql-ws protocol and interchangeable drivers.
- **Nested Input DTO Hydration**: Type-hint `readonly` DTO classes in your mutations; Typic recursively hydrates nested GraphQL input objects into strongly-typed PHP objects.
- **Built-in DataLoader**: Solve N+1 query problems effortlessly with first-class batch loading and lifecycle caching.
- **First-Class Pagination**: Automatic schema generation for both offset-based (`Paginator`) and Relay cursor-based (`Connection`) pagination.
- **PSR-7 & PSR-15 Standard Compliant**: Integrates with any modern PHP framework (Laravel, Symfony, Spiral, Slim, Mezzio) or standalone HTTP runtimes.
- **Embedded Apollo Sandbox**: Industry-standard, visual query builder and developer explorer served automatically at `/graphql` for browser requests.
- **Directory Scanner & Auto-Discovery**: Automatically scans your project directories and registers types, queries, mutations, and field resolvers.
- **Strictly Typed & Refactoring Safe**: Fully compatible with PHPStan (Level 8) and IDE refactoring tools.

---

## Installation

```bash
composer require typic/typic
```

---

## Quickstart Example

### 1. Define an Object Type

```php
namespace App\GraphQL\Types;

use Typic\Attribute\ObjectType;
use Typic\Attribute\Field;
use Typic\Attribute\ID;

#[ObjectType(name: 'Post', description: 'A blog post')]
class Post
{
    #[Field]
    #[ID]
    public int $id;

    #[Field]
    public string $title;

    #[Field]
    public ?string $content = null;

    public int $authorId;

    public function __construct(int $id, string $title, ?string $content, int $authorId)
    {
        $this->id = $id;
        $this->title = $title;
        $this->content = $content;
        $this->authorId = $authorId;
    }

    // Computed field directly on the type
    #[Field]
    public function snippet(): string
    {
        return mb_substr($this->content ?? '', 0, 100) . '...';
    }
}
```

---

### 2. Define Nested Input DTOs

```php
namespace App\GraphQL\Inputs;

use Typic\Attribute\InputType;
use Typic\Attribute\Field;

#[InputType]
readonly class PostMetadataInput
{
    public function __construct(
        #[Field] public ?string $seoDescription = null,
        #[Field(arrayOf: 'string')] public array $tags = [],
    ) {}
}

#[InputType]
readonly class CreatePostInput
{
    public function __construct(
        #[Field] public string $title,
        #[Field] public string $content,
        #[Field] public ?PostMetadataInput $metadata = null,
    ) {}
}
```

---

### 3. Create Resolvers & Mutations

```php
namespace App\GraphQL\Resolvers;

use App\GraphQL\Types\Post;
use App\GraphQL\Inputs\CreatePostInput;
use Typic\Attribute\Query;
use Typic\Attribute\Mutation;
use Typic\Attribute\Arg;
use Typic\Pagination\Paginator;

class PostResolver
{
    /**
     * Inferred GraphQL:
     * posts(published: Boolean): [Post!]!
     */
    #[Query(arrayOf: Post::class)]
    public function posts(?bool $published = null): array
    {
        return Post::query()->when($published !== null, fn ($q) => $q->where('published', $published))->get()->all();
    }

    /**
     * Inferred GraphQL:
     * paginatedPosts(page: Int = 1, perPage: Int = 15): PostPaginator!
     */
    #[Query(arrayOf: Post::class)]
    public function paginatedPosts(
        #[Arg(defaultValue: 1)] int $page = 1,
        #[Arg(defaultValue: 15)] int $perPage = 15,
    ): Paginator {
        $paginator = Post::paginate($perPage, page: $page);
        return Paginator::from($paginator->items(), $page, $perPage, $paginator->total());
    }

    /**
     * Inferred GraphQL:
     * createPost(input: CreatePostInput!): Post!
     */
    #[Mutation]
    public function createPost(CreatePostInput $input): Post
    {
        // $input is fully hydrated with nested metadata!
        return Post::create([
            'title' => $input->title,
            'content' => $input->content,
            'seo_description' => $input->metadata?->seoDescription,
        ]);
    }
}
```

---

### 4. Resolve Relationships with DataLoader (Zero N+1)

```php
namespace App\GraphQL\DataLoaders;

use Typic\DataLoader\DataLoader;
use App\Models\User;

class UserDataLoader extends DataLoader
{
    protected mixed $keyBy = 'id';

    protected function batch(array $keys): iterable
    {
        return User::whereIn('id', $keys)->get();
    }
}
```

Attach the relationship field resolver:

```php
namespace App\GraphQL\Resolvers;

use App\GraphQL\Types\Post;
use App\GraphQL\Types\User;
use App\GraphQL\DataLoaders\UserDataLoader;
use GraphQL\Deferred;
use Typic\Attribute\ResolveField;

class PostFieldResolver
{
    #[ResolveField(target: Post::class, name: 'author', type: User::class)]
    public function author(Post $post, UserDataLoader $loader): Deferred
    {
        return $loader->load($post->authorId);
    }
}
```

---

### 5. Run the Server

```php
use Typic\Typic;

$server = Typic::builder()
    ->scanDirectories([__DIR__ . '/GraphQL'])
    ->withContainer($container)
    ->withSandbox(true)
    ->build();

// In any PSR-15 handler or controller:
$response = $server->handle($psr7Request);
```

Or execute programmatically:

```php
$result = $server->execute(
    query: '{ posts { id title snippet author { name } } }'
);
```

---

### 6. Real-Time Subscriptions (Workerman)

Typic features built-in WebSocket subscriptions using the standard `graphql-transport-ws` protocol:

```php
namespace App\GraphQL\Resolvers;

use App\GraphQL\Types\Comment;
use Typic\Attribute\ID;
use Typic\Attribute\Payload;
use Typic\Attribute\Subscription;

class CommentSubscriptionResolver
{
    #[Subscription(name: 'commentAdded', type: Comment::class, topic: 'COMMENT_ADDED')]
    public function commentAdded(#[ID] ?int $postId = null, #[Payload] ?Comment $payload = null): bool
    {
        return $postId === null || $payload?->postId === $postId;
    }
}
```

Run with Workerman:

```php
use Typic\Subscription\Driver\Workerman\WorkermanDriver;
use Typic\Typic;

$server = Typic::builder()
    ->scanDirectories([__DIR__ . '/GraphQL'])
    ->withSubscriptions(
        driver: new WorkermanDriver(host: '0.0.0.0', port: 8080),
        endpoint: 'ws://localhost:8080'
    )
    ->build();

// Start the Workerman event loop
$server->getSubscriptionServer()->start();
```

---

### 7. Switchable GraphQL Explorers

Typic supports three embedded developer explorers:

```php
// 1. GraphiQL (Native DOM, zero iframes, direct WebSockets)
$server = Typic::builder()
    ->withGraphiQL()
    ->build();

// 2. Altair GraphQL Client (Native DOM, multi-tab, zero iframes)
$server = Typic::builder()
    ->withAltair()
    ->build();

// 3. Apollo Sandbox (Official Apollo Studio embed)
$server = Typic::builder()
    ->withSandbox()
    ->build();
```

---

## Testing & Quality

Typic is tested with PHPUnit and verified with PHPStan at **Level 8**:

```bash
composer test
composer phpstan
```

---

## License

Typic is open-source software licensed under the [MIT license](LICENSE).


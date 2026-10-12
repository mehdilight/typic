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
- **Real-Time Subscriptions**: Driver-based WebSocket subscription engine powered by Workerman, supporting the standard graphql-ws protocol, interchangeable drivers, and Redis Pub/Sub for multi-process delivery.
- **Nested Input DTO Hydration**: Type-hint `readonly` DTO classes in your mutations; Typic recursively hydrates nested GraphQL input objects into strongly-typed PHP objects.
- **Built-in DataLoader**: Write one `batch()` method and call `$loader->get($id)` in resolvers; Typic turns N lookups into one query.
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

Resolving `author` for 100 posts one by one runs 100 queries. A DataLoader collects all 100 ids and runs one.

**Step 1: write the loader.** `batch()` receives every requested key at once and returns the results keyed by those keys:

```php
namespace App\GraphQL\DataLoaders;

use App\GraphQL\Types\User;
use Typic\DataLoader\DataLoader;

/** @extends DataLoader<int, User|null> */
class UserLoader extends DataLoader
{
    protected function batch(array $ids): iterable
    {
        return $this->keyBy(User::findMany($ids), 'id');   // [id => User]
    }
}
```

**Step 2: use it in a resolver.** Take the loader as a parameter and call `get()`:

```php
#[ResolveField(target: Post::class)]
public function author(Post $post, UserLoader $users): ?User
{
    return $users->get($post->authorId);
}
```

That's it. `get()` reads like a normal function call, but Typic pauses the resolver until every post on the page has asked for its author, then calls `batch()` once with all the ids. The field type comes from the return type, as usual.

For one-to-many relations, build the result with `groupBy()`. Every requested key gets a list, so posts without comments get `[]`:

```php
/** @extends DataLoader<int, list<Comment>> */
class CommentsByPostLoader extends DataLoader
{
    protected function batch(array $postIds): iterable
    {
        return $this->groupBy(Comment::wherePostIdIn($postIds), 'postId');   // [postId => list<Comment>]
    }
}

/** @return list<Comment> */
#[ResolveField(target: Post::class, arrayOf: Comment::class)]
public function comments(Post $post, CommentsByPostLoader $comments): array
{
    return $comments->get($post->id);
}
```

Good to know:
- **Missing keys:** keys that `batch()` does not return resolve to `null`.
- **One per request:** each request gets its own loader instance, and results are cached for that request (`clear($key)` after a mutation if needed).
- **Mistakes are caught:** returning a plain list instead of a keyed array throws an error that names the fix.
- **More helpers:** `getMany($keys)` loads several keys at once, and `prime($key, $value)` stores a value you already have.
- **Outside a resolver parameter:** `load($key)` returns a `GraphQL\Deferred` instead, for code that receives the loader some other way.

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

Serve everything with Workerman. Queries, mutations, and subscriptions share one event loop and one port: HTTP requests to `/graphql` are answered directly, and WebSocket upgrade requests to the same URL switch to `graphql-transport-ws`.

```php
use Typic\Subscription\Driver\Workerman\WorkermanDriver;
use Typic\Typic;

$server = Typic::builder()
    ->scanDirectories([__DIR__ . '/GraphQL'])
    ->withSubscriptions(driver: new WorkermanDriver(host: '0.0.0.0', port: 4000))
    ->build();

// HTTP: http://localhost:4000/graphql, WebSocket: ws://localhost:4000/graphql
$server->serve();
```

Run it with `php server.php start` (add `-d` to daemonize).

Events travel through Redis by default (`REDIS_URL`, falling back to `redis://127.0.0.1:6379`, channels prefixed with `typic:`). Any number of Workerman workers, and any other PHP process that publishes through `RedisPubSub` (PHP-FPM, queue workers, CLI), reaches every subscriber. No PHP extension is needed: Typic speaks the Redis protocol directly, without blocking the event loop.

```php
use Typic\Subscription\PubSub\InMemoryPubSub;
use Typic\Subscription\PubSub\RedisPubSub;

// Custom Redis server or channel prefix
->withSubscriptions(pubSub: RedisPubSub::fromUrl('redis://user:secret@redis.internal:6379', prefix: 'shop:'))

// No Redis: single process only, so keep workerCount at 1
->withSubscriptions(pubSub: new InMemoryPubSub())
```

Payloads are PHP-serialized so resolvers receive the published objects; only use a Redis instance you trust.

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

Any explorer can also be opened per request with `?explorer=graphiql`, `?explorer=altair`, or `?explorer=apollo`.

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


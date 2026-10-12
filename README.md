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
- **Resolver Middleware**: Stack checks and transforms around resolvers with `#[Middleware]` on a method or a whole class, named groups, and global middleware. Works for queries, mutations, field resolvers, and subscriptions.
- **First-Class Pagination**: Automatic schema generation for both offset-based (`Paginator`) and Relay cursor-based (`Connection`) pagination.
- **Standalone Application Runtime**: `Typic\Application` owns PHP-DI container setup and starts the Workerman HTTP/WebSocket server. PSR-7 and PSR-15 remain the HTTP boundaries.
- **Embedded Apollo Sandbox**: Industry-standard, visual query builder and developer explorer served automatically at `/graphql` for browser requests.
- **Directory Scanner & Auto-Discovery**: Automatically scans your project directories and registers types, queries, mutations, and field resolvers.
- **Strictly Typed & Refactoring Safe**: Fully compatible with PHPStan (Level 8) and IDE refactoring tools.

---

## Installation

```bash
composer require typic/typic
```

---

## Standalone Application

Typic supplies the application bootstrap and default DI container. Your project supplies service definitions and GraphQL configuration:

```php
use Typic\Application;
use Typic\Subscription\Driver\Workerman\WorkermanDriver;

$app = new Application([
    // Interfaces need a binding; concrete services autowire by constructor type.
    UserRepository::class => DI\autowire(SqliteUserRepository::class),
]);

$app->builder()
    ->scanDirectories([__DIR__ . '/src/GraphQL'])
    ->withGraphiQL()
    ->withSubscriptions(driver: new WorkermanDriver(port: 8000));

$app->serve();
```

Run the entry point with `php server.php start`. `container()` exposes the shared application services; `builder()` exposes GraphQL configuration; `build()` creates a server without starting the event loop. Service factories stay lazy so database connections open after Workerman forks. Application automatically creates fresh DataLoaders per request or subscription event through PHP-DI's factory API.

For another PSR-11 container, pass `new Application(container: $container, dataLoaderFactory: $factory)`. Supply bindings to that container directly. `Typic::builder()` remains available when you want to assemble the components yourself.

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
- **Containers that share instances:** use `withDataLoaderFactory($factory)` to construct fresh loaders for each execution. The factory receives the loader class and owns its construction. For PHP-DI, pass `$container->make(...)`; Typic still reuses that loader across sibling fields within the request. Input DTOs and scalars stay GraphQL arguments even when the container can autowire them.
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

### 8. Resolver Middleware

A middleware runs around a resolver. It can check something first, pass values to the resolver, change the result, or stop the resolver from running:

```php
use GraphQL\Error\UserError;
use Typic\Middleware\ResolverCall;
use Typic\Middleware\ResolverMiddleware;

final class Authenticated implements ResolverMiddleware
{
    public function __construct(private Users $users) {}

    public function handle(ResolverCall $call, callable $next): mixed
    {
        // A header over HTTP; WebSocket clients usually send the token in connection_init
        $token = $call->request()?->getHeaderLine('Authorization')
            ?: ($call->connectionParams()['Authorization'] ?? '');
        $user = $this->users->fromToken($token) ?? throw new UserError('You must be logged in.');

        return $next($call->with('user', $user));
    }
}
```

Attach middleware to a class (every field in it) or to a method. Each entry is an instance, a class (built by your container when it has it), or a group name:

```php
#[Middleware(Authenticated::class)]
class PostResolver
{
    #[Mutation]
    #[Middleware(new Can('posts.publish'))]
    #[Middleware(new RateLimit(perMinute: 10))]
    public function publishPost(#[ID] int $id, #[FromCall('user')] User $user): Post { /* ... */ }

    #[Query]
    #[WithoutMiddleware(Authenticated::class)]
    public function publicFeed(): array { /* ... */ }
}
```

`#[FromCall('user')]` receives the value a middleware passed with `$call->with('user', ...)`. It is not a GraphQL argument.

Register global middleware and named groups on the builder. Groups may include other groups:

```php
$server = Typic::builder()
    ->withResolverMiddleware(new LogSlowFields(thresholdMs: 50))
    ->withMiddlewareGroup('admin', [Authenticated::class, new Can('admin'), AuditLog::class])
    ->build();

// #[Middleware('admin')] on a class or method
```

**Order**, outermost first: global middleware, then the class's `#[Middleware]`, then the method's, each in the order written. For `publishPost` above that is `Authenticated -> Can -> RateLimit -> publishPost`, and the result passes back out through each one. Middleware listed twice runs twice. `#[WithoutMiddleware]` takes classes or group names and removes them from what the method inherits (global and class); the method's own middleware still runs.

**`ResolverCall`** gives `args()`, `arg($name)`, `withArgs($args)`, `parent()`, `field()` (for example `"Mutation.publishPost"`), `request()`, `connectionParams()`, `context()`, `info()`, `subscribing()`, and `with()`, `get()`, `has()` for values passed along.

**Subscriptions**: when a client subscribes, the field's middleware runs without the resolver (`$call->subscribing()` is true and `$next()` returns `null`). Throwing refuses the subscription with an `error` message. The middleware then runs again, with the resolver, for every event. Over WebSocket, `request()` is the HTTP request that opened the connection.

**DataLoaders**: for resolvers that call `$loader->get()`, `$next()` returns the loaded value, and batching still applies.

Unknown classes or group names, and groups that include themselves, fail when the schema is built.

---

### 9. HTTP Middleware and CORS

PSR-15 middleware runs in registration order, first outermost, around HTTP requests and WebSocket upgrades. CORS preflight requests are handled before middleware. Classes come from your container, or are constructed using dependencies it provides:

```php
use Typic\Attribute\FromRequest;

$server = Typic::builder()
    ->withMiddleware(new TokenAuthentication($users), RequestLogger::class)
    ->withCors(origins: ['https://app.example.com'], credentials: true)
    ->build();

// TokenAuthentication sets $request->withAttribute('user', $user)
#[Query]
public function me(#[FromRequest('user')] ?User $user): ?User
{
    return $user;
}
```

The default CORS policy allows any origin **without credentials**. Cookies require an explicit origin list; wildcard origins with credentials are rejected. The origin list also checks browser WebSocket upgrades. Use `withCors(false)` to disable CORS handling. Mutations over GET return 405. Non-JSON POST requests require a non-empty `GraphQL-Require-Preflight` or `Apollo-Require-Preflight` header by default; `withCsrfProtection(false)` explicitly disables that check.

### 10. Interfaces, Unions and Scalars

Mark a PHP interface or abstract class with `#[InterfaceType]`, and its methods with `#[Field]`. Object types inherit those field definitions. Use `#[UnionType(types: [Cat::class, Dog::class])]` on a class or interface to define a union. Return the concrete PHP objects; Typic resolves their GraphQL types. Register all concrete types or include them in your scanned directories.

`DateTimeInterface`, `DateTimeImmutable` and `DateTime` map to the built-in `DateTime` scalar. Inputs require an ISO 8601 timestamp with a timezone, such as `2026-10-12T10:00:00+00:00`. Resolvers receive the PHP date type they ask for.

For your own value objects, implement `ScalarValue`:

```php
use Typic\Attribute\Scalar;
use Typic\Scalar\ScalarValue;

#[Scalar(description: 'An email address')]
final readonly class Email implements ScalarValue
{
    public function __construct(public string $value) {}

    public static function parse(mixed $value): static
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }
        return new self($value);
    }

    public function serialize(): string { return $this->value; }
}
```

Use `Email` as a resolver argument, input property or return type. For classes from another package, implement `Typic\Scalar\ScalarType` (`name()`, `description()`, `parse()`, `serialize()`) and register it with `withScalar(Uuid::class, new UuidScalar())`. Scalar parse rejection messages are visible to clients; keep them suitable for public display.

### 11. Input Validation and Uploads

Rules are PHP attributes on resolver parameters or input properties. Typic checks arguments after resolver middleware changes them and before hydration or resolver execution. All violations appear under `errors[].extensions.validation`, keyed by paths such as `input.address.city` or `input.tags.2`.

```php
use Typic\Validation as Assert;

#[InputType]
final readonly class SignupInput
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Length(max: 32)] public string $name,
        #[Assert\Email] public string $email,
        #[Assert\Range(min: 13)] public int $age,
        #[Field(arrayOf: 'string')]
        #[Assert\Distinct(ignoreCase: true)]
        #[Assert\Each(new Assert\Length(max: 20))] public array $tags = [],
    ) {}
}
```

Other rules include `Url`, `Pattern`, `OneOf`, `NotOneOf`, `Alpha`, `AlphaNum`, `AlphaDash`, `Uuid`, `Ip`, `Json`, `Timezone`, `HexColor`, `MultipleOf`, `Same`, `Different`, `RequiredIf`, `RequiredUnless`, `RequiredWith`, `RequiredWithout`, `Before` and `After`. Ordinary rules skip null; implicit rules such as `NotBlank` and conditional required rules check it. Dependent rules refer to sibling GraphQL field names, including renamed fields. Nested inputs and lists are validated recursively. Custom attribute classes can implement `Rule` or `DependentRule`; implement `ImplicitRule` too when missing values must be checked.

For files, type arguments or input properties as `Psr\Http\Message\UploadedFileInterface`. Clients send the [GraphQL multipart request format](https://github.com/jaydenseric/graphql-multipart-request-spec), with `operations`, `map`, and file parts, plus the preflight header above. File lists use `#[Arg(arrayOf: UploadedFileInterface::class)]`. One file can map to several null placeholders; batched operations are currently unsupported.

```php
#[Mutation]
public function avatar(
    #[Assert\MaxFileSize(2 * 1024 * 1024)]
    #[Assert\MimeType('image/png', 'image/jpeg')]
    UploadedFileInterface $file,
): bool {
    $file->moveTo('/srv/uploads/' . bin2hex(random_bytes(16)));
    return true;
}
```

`MimeType` checks the file content and requires PHP's fileinfo extension. `Upload` is intended for inputs. Workerman converts multipart files to PSR-7; under another HTTP host, provide a PSR-7 request with parsed form data and uploaded files.

### 12. Deployment Settings and Subscription Lifecycle

```php
use Typic\Subscription\SubscriptionOptions;

$server = Typic::builder()
    ->scanDirectories([__DIR__ . '/GraphQL'])
    ->withCache(__DIR__ . '/var/typic')
    ->withQueryLimits(maxDepth: 8, maxComplexity: 200)
    ->withIntrospection(false)
    ->withMaxRequestSize(1024 * 1024)
    ->withLogger($psr3Logger)
    ->withSubscriptions(options: new SubscriptionOptions(
        keepAlive: 30,
        connectionInitTimeout: 5,
        maxSubscriptionsPerConnection: 100,
        onConnectionInit: static fn (array $params, $request): bool => $tokens->valid($params['token'] ?? null),
    ))
    ->build();
```

`withCache()` caches directory discovery in a PHP file, and types compile lazily. It does **not** serialize the compiled schema. Clear the cache on deployment; leave it off during development. Missing required resolver or loader constructor dependencies fail during schema building with the class and parameter named. Container-provided objects remain the container's responsibility.

Depth follows graphql-php's convention: root fields start at 0, and scalar leaf fields do not add a level. Complexity counts fields, rather than estimating returned list sizes. Limits apply to direct execution, HTTP and subscriptions. Introspection controls `__schema` and `__type`; `__typename` remains available. Explorers and code generators need introspection. The request size limit rejects oversized HTTP bodies with 413 and WebSocket messages with close code 1009, after receipt; transport-level buffering limits should also be configured in your host.

A PSR-3 logger receives unexpected execution, middleware, handshake and subscription failures with exception, field path and operation context where available. Client-safe errors, validation failures and syntax errors are excluded. Internal exception details remain hidden unless debug mode is enabled.

Workerman checks connection deadlines every second. Clients must send `connection_init` within the configured timeout; the hook can refuse it with `false` (4403). Clients answer heartbeat pings with pong; connections silent for two keepalive intervals are closed and their subscriptions removed. Set `keepAlive: 0` to disable heartbeat checks. Duplicate initialization, subscriptions before acknowledgment, duplicate active IDs and malformed messages close with the corresponding graphql-transport-ws code. Completing an operation frees its slot.

Custom drivers can implement `Typic\Subscription\Driver\TickAwareInterface` to invoke the handler every second, or call `getProtocolHandler()->tick()` from their event loop. Subscription resolver middleware still runs at subscribe time and for every event; `connectionParams()` exposes initialization data.

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


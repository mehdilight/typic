<?php

declare(strict_types=1);

namespace Typic\Tests;

use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Typic\Context;
use Typic\Server\TypicBuilder;
use Typic\Server\TypicServer;
use Typic\Tests\Fixtures\Library\Author;
use Typic\Tests\Fixtures\Library\Book;
use Typic\Tests\Fixtures\Library\Library;
use Typic\Tests\Fixtures\Library\LibraryResolver;
use Typic\Tests\MiddlewareFixtures\AccountResolver;
use Typic\Tests\MiddlewareFixtures\Badge;
use Typic\Tests\MiddlewareFixtures\BrokenResolver;
use Typic\Tests\MiddlewareFixtures\Can;
use Typic\Tests\MiddlewareFixtures\GroupResolver;
use Typic\Tests\MiddlewareFixtures\Inspect;
use Typic\Tests\MiddlewareFixtures\NeedsArgsResolver;
use Typic\Tests\MiddlewareFixtures\NotMiddlewareResolver;
use Typic\Tests\MiddlewareFixtures\Record;
use Typic\Tests\MiddlewareFixtures\ShoutingLibraryResolver;
use Typic\Tests\MiddlewareFixtures\StackedResolver;
use Typic\Tests\MiddlewareFixtures\ToolsResolver;
use Typic\Tests\MiddlewareFixtures\Trace;
use Typic\Tests\MiddlewareFixtures\UnknownWithoutResolver;
use Typic\Typic;

class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Trace::$log = [];
        Inspect::$last = null;
        Library::$queries = [];
    }

    public function test_stack_runs_global_then_class_then_method_around_the_resolver(): void
    {
        $result = $this->stacked()->execute('{ stacked }');

        $this->assertSame(['data' => ['stacked' => 'ok']], $result);
        $this->assertSame([
            'before global',
            'before class 1',
            'before class 2',
            'before method 1',
            'before method 2',
            'resolver',
            'after method 2',
            'after method 1',
            'after class 2',
            'after class 1',
            'after global',
        ], Trace::$log);
    }

    public function test_class_middleware_wraps_every_field_of_the_class(): void
    {
        $this->stacked()->execute('{ classOnly }');

        $this->assertSame([
            'before global',
            'before class 1',
            'before class 2',
            'resolver',
            'after class 2',
            'after class 1',
            'after global',
        ], Trace::$log);
    }

    public function test_middleware_runs_every_time_it_appears(): void
    {
        $this->stacked()->execute('{ twice }');

        // Once from the 'traced' group, once listed directly
        $this->assertSame(2, count(array_keys(Trace::$log, 'before traced', true)));
        $this->assertSame(
            ['before traced', 'before traced', 'resolver', 'after traced', 'after traced'],
            array_slice(Trace::$log, 3, 5),
        );
    }

    public function test_without_middleware_removes_inherited_middleware_only(): void
    {
        $this->stacked()->execute('{ withoutInheritedRecords }');

        // Global and class-level Records are gone; the method's own Record stays
        $this->assertSame(['before own', 'resolver', 'after own'], Trace::$log);
    }

    public function test_middleware_can_stop_the_resolver_from_running(): void
    {
        $result = $this->send($this->accounts(), '{ adminPanel }');

        $this->assertSame('You must be logged in.', $result['errors'][0]['message']);
        $this->assertNull($result['data']['adminPanel'] ?? null);
        $this->assertNotContains('resolver', Trace::$log);
    }

    public function test_named_group_expands_in_place(): void
    {
        $server = $this->accounts();

        $alice = $this->send($server, '{ adminPanel }', 'alice-token');
        $this->assertSame(['data' => ['adminPanel' => 'secrets']], $alice);
        $this->assertSame(['before audit', 'resolver', 'after audit'], Trace::$log);

        Trace::$log = [];
        $bob = $this->send($server, '{ adminPanel }', 'bob-token');
        $this->assertSame("You need the 'admin' ability.", $bob['errors'][0]['message']);
        $this->assertSame([], Trace::$log);
    }

    public function test_values_set_by_middleware_reach_the_resolver(): void
    {
        $result = $this->send($this->accounts(), '{ whoami }', 'bob-token');

        $this->assertSame(['data' => ['whoami' => 'bob']], $result);
    }

    public function test_from_call_parameters_are_not_graphql_arguments(): void
    {
        $field = $this->accounts()->getSchema()->getQueryType()?->getField('whoami');

        $this->assertSame([], $field?->args);
    }

    public function test_optional_from_call_value_falls_back_to_its_default(): void
    {
        $result = $this->send($this->accounts(), '{ greeting }');

        $this->assertSame(['data' => ['greeting' => 'hello guest']], $result);
    }

    public function test_missing_required_from_call_value_explains_the_fix(): void
    {
        $result = $this->accounts(debug: true)->execute('{ needsUser }');

        $message = $result['errors'][0]['extensions']['debugMessage'] ?? '';
        $this->assertStringContainsString("#[FromCall('user')]", $message);
        $this->assertStringContainsString("\$call->with('user'", $message);
    }

    public function test_middleware_can_change_arguments_and_the_result(): void
    {
        $result = $this->tools()->execute('{ shout(text: "  hi  ") }');

        $this->assertSame(['data' => ['shout' => 'HI!']], $result);
    }

    public function test_middleware_can_answer_without_calling_the_resolver(): void
    {
        $result = $this->tools()->execute('{ early }');

        $this->assertSame(['data' => ['early' => 'from middleware']], $result);
        $this->assertSame([], Trace::$log);
    }

    public function test_resolver_call_describes_the_field(): void
    {
        $context = new Context(['tenant' => 'acme']);
        $this->tools()->execute('{ inspected(limit: 3) }', context: $context);

        $call = Inspect::$last;
        $this->assertNotNull($call);
        $this->assertSame('Query.inspected', $call->field());
        $this->assertSame(['limit' => 3], $call->args());
        $this->assertSame(3, $call->arg('limit'));
        $this->assertNull($call->arg('missing'));
        $this->assertNull($call->parent());
        $this->assertSame($context, $call->context());
        $this->assertSame('inspected', $call->info()->fieldName);
        $this->assertNull($call->request());
        $this->assertFalse($call->subscribing());
        $this->assertSame([], $call->connectionParams());
    }

    public function test_with_returns_a_new_call_and_keeps_the_original_unchanged(): void
    {
        $this->tools()->execute('{ inspected(limit: 1) }');
        $call = Inspect::$last;
        $this->assertNotNull($call);

        $withUser = $call->with('user', 'alice');

        $this->assertNotSame($call, $withUser);
        $this->assertFalse($call->has('user'));
        $this->assertSame('alice', $withUser->get('user'));
        $this->assertSame('fallback', $call->get('user', 'fallback'));
    }

    public function test_class_middleware_on_an_object_type_wraps_its_field_methods_not_properties(): void
    {
        $result = $this->tools()->execute('{ badge { label shine } }');

        $this->assertSame(['data' => ['badge' => ['label' => 'gold', 'shine' => 'shiny']]], $result);
        $this->assertSame(['before type', 'resolver', 'after type'], Trace::$log);
    }

    public function test_middleware_sees_the_loaded_value_of_dataloader_resolvers(): void
    {
        $server = Typic::builder()
            ->registerResolvers([LibraryResolver::class, ShoutingLibraryResolver::class])
            ->registerTypes([Author::class, Book::class])
            ->build();

        $result = $server->execute('{ books { shoutedAuthor } }');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame(
            ['URSULA', 'TERRY', 'URSULA', 'UNKNOWN'],
            array_column($result['data']['books'], 'shoutedAuthor'),
        );
        // Middleware does not break batching
        $this->assertSame(['authors 1,2,99'], Library::$queries);
        $this->assertInstanceOf(Book::class, Inspect::$last?->parent());
        $this->assertSame('Book.shoutedAuthor', Inspect::$last->field());
    }

    public function test_global_middleware_can_be_a_class_name(): void
    {
        $server = $this->builder([ToolsResolver::class])
            ->withResolverMiddleware(Inspect::class)
            ->build();

        $server->execute('{ inspected(limit: 2) }');

        $this->assertSame(['limit' => 2], Inspect::$last?->args());
    }

    public function test_unknown_middleware_fails_when_the_schema_is_built(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'missing' on " . BrokenResolver::class . '::broken()');

        $this->builder([BrokenResolver::class])->build();
    }

    public function test_groups_can_include_other_groups(): void
    {
        $this->grouped()->build()->execute('{ nested }');

        $this->assertSame(
            ['before inner', 'before outer', 'resolver', 'after outer', 'after inner'],
            Trace::$log,
        );
    }

    public function test_global_middleware_and_without_middleware_accept_group_names(): void
    {
        $server = $this->grouped()->withResolverMiddleware('inner')->build();

        $server->execute('{ nested }');
        $this->assertSame(['before inner', 'before inner', 'before outer', 'resolver'], array_slice(Trace::$log, 0, 4));

        Trace::$log = [];
        $server->execute('{ withoutGroup }');
        $this->assertSame(['resolver'], Trace::$log);
    }

    public function test_unknown_entry_in_a_group_names_the_group(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown middleware 'nope' in group 'outer'");

        $this->builder([GroupResolver::class])
            ->withMiddlewareGroup('inner', [new Record('inner')])
            ->withMiddlewareGroup('outer', ['nope'])
            ->build();
    }

    public function test_groups_that_include_themselves_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Middleware group 'outer' includes itself");

        $this->builder([GroupResolver::class])
            ->withMiddlewareGroup('inner', [new Record('inner')])
            ->withMiddlewareGroup('outer', ['inner', 'outer'])
            ->build();
    }

    public function test_a_class_that_is_not_middleware_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown middleware 'stdClass' on " . NotMiddlewareResolver::class . '::wrong()');

        $this->builder([NotMiddlewareResolver::class])->build();
    }

    public function test_unknown_without_middleware_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown middleware 'nope' in #[WithoutMiddleware] on " . UnknownWithoutResolver::class . '::wrong()');

        $this->builder([UnknownWithoutResolver::class])->build();
    }

    public function test_middleware_class_with_constructor_arguments_needs_new_or_the_container(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('write new ' . Can::class . '(...) in the attribute, or register ' . Can::class . ' in the container');

        $this->builder([NeedsArgsResolver::class])->build();
    }

    public function test_middleware_classes_come_from_the_container(): void
    {
        $server = $this->builder([NeedsArgsResolver::class])
            ->withContainer($this->container([Can::class => new Can('admin')]))
            ->build();

        $result = $server->execute('{ guarded }');

        $this->assertSame("You need the 'admin' ability.", $result['errors'][0]['message']);
    }

    public function test_container_entries_must_be_middleware(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The container entry for ' . Can::class . ' does not implement');

        $this->builder([NeedsArgsResolver::class])
            ->withContainer($this->container([Can::class => new \stdClass()]))
            ->build();
    }

    private function grouped(): TypicBuilder
    {
        return $this->builder([GroupResolver::class])
            ->withMiddlewareGroup('inner', [new Record('inner')])
            ->withMiddlewareGroup('outer', ['inner', new Record('outer')]);
    }

    /**
     * @param array<string, object> $entries
     */
    private function container(array $entries): ContainerInterface
    {
        return new class ($entries) implements ContainerInterface {
            /**
             * @param array<string, object> $entries
             */
            public function __construct(private array $entries)
            {
            }

            public function get(string $id): object
            {
                return $this->entries[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->entries[$id]);
            }
        };
    }

    private function stacked(): TypicServer
    {
        return $this->builder([StackedResolver::class])
            ->withResolverMiddleware(new Record('global'))
            ->withMiddlewareGroup('traced', [new Record('traced')])
            ->build();
    }

    private function accounts(bool $debug = false): TypicServer
    {
        return $this->builder([AccountResolver::class])
            ->withMiddlewareGroup('admin', [new Can('admin'), new Record('audit')])
            ->withDebug($debug)
            ->build();
    }

    private function tools(): TypicServer
    {
        return $this->builder([ToolsResolver::class])->registerTypes([Badge::class])->build();
    }

    /**
     * @param list<class-string> $resolvers
     */
    private function builder(array $resolvers): TypicBuilder
    {
        return Typic::builder()->registerResolvers($resolvers)->withGraphiQL(false);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(TypicServer $server, string $query, ?string $token = null): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        $request = new ServerRequest('POST', '/graphql', $headers, (string) json_encode(['query' => $query]));
        $response = $server->handle($request);

        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true);
    }
}

<?php

declare(strict_types=1);

namespace Typic\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Typic\Server\TypicBuilder;
use Typic\Tests\HttpFixtures\AddHeader;
use Typic\Tests\HttpFixtures\TokenAuth;
use Typic\Tests\HttpFixtures\WhoAmIResolver;
use Typic\Typic;

class HttpMiddlewareTest extends TestCase
{
    public function test_middleware_can_set_request_attributes_for_resolvers(): void
    {
        $server = $this->builder()->withMiddleware(new TokenAuth())->build();

        $this->assertSame(['data' => ['whoami' => 'mehdi']], $this->json($server->handle($this->query('{ whoami }', 'mehdi'))));
        $this->assertSame(['data' => ['whoami' => 'anonymous']], $this->json($server->handle($this->query('{ whoami }'))));
    }

    public function test_a_missing_required_request_attribute_explains_the_fix(): void
    {
        $server = $this->builder()->withMiddleware(new TokenAuth())->withDebug()->build();

        $body = $this->json($server->handle($this->query('{ mustBeUser }')));

        $this->assertStringContainsString("#[FromRequest('user')]", $body['errors'][0]['extensions']['debugMessage'] ?? '');
    }

    public function test_middleware_can_answer_on_its_own_and_cors_still_applies(): void
    {
        $server = $this->builder()->withMiddleware(new TokenAuth())->build();

        $response = $server->handle($this->query('{ whoami }', 'banned')->withHeader('Origin', 'https://app.example'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Go away', (string) $response->getBody());
        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function test_middleware_runs_in_the_order_given_first_outermost(): void
    {
        $server = $this->builder()
            ->withMiddleware(new AddHeader('outer'), AddHeader::class)
            ->withMiddleware(new AddHeader('inner'))
            ->build();

        $response = $server->handle($this->query('{ whoami }'));

        // Headers are added on the way out, so the innermost adds first
        $this->assertSame(['inner', 'default', 'outer'], $response->getHeader('X-Trace'));
    }

    public function test_preflight_requests_skip_middleware(): void
    {
        $server = $this->builder()->withMiddleware(new TokenAuth(), new AddHeader('seen'))->build();

        $response = $server->handle(new ServerRequest('OPTIONS', '/graphql', ['Origin' => 'https://app.example', 'Authorization' => 'Bearer banned']));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('X-Trace'));
    }

    public function test_websocket_handshakes_go_through_the_same_middleware(): void
    {
        $server = $this->builder()->withMiddleware(new TokenAuth())->build();

        $accepted = $server->handshake(new ServerRequest('GET', '/graphql', ['Authorization' => 'Bearer mehdi']));
        $this->assertInstanceOf(ServerRequestInterface::class, $accepted);
        $this->assertSame('mehdi', $accepted->getAttribute('user'));

        $refused = $server->handshake(new ServerRequest('GET', '/graphql', ['Authorization' => 'Bearer banned']));
        $this->assertInstanceOf(ResponseInterface::class, $refused);
        $this->assertSame(401, $refused->getStatusCode());
    }

    public function test_handshake_middleware_errors_are_logged_and_refused(): void
    {
        $logger = new \Typic\Tests\Support\MemoryLogger();
        $server = $this->builder()->withMiddleware(new \Typic\Tests\HttpFixtures\ExplodingMiddleware())
            ->withLogger($logger)->build();
        $response = $server->handshake(new ServerRequest('GET', '/graphql'));
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('Session store', (string) $response->getBody());
        $this->assertCount(1, $logger->records);
    }

    public function test_websocket_origins_follow_the_configured_allowlist(): void
    {
        $server = $this->builder()->withCors(origins: ['https://app.example'], credentials: true)->build();
        $foreign = $server->handshake(new ServerRequest('GET', '/graphql', ['Origin' => 'https://evil.example']));
        $this->assertInstanceOf(ResponseInterface::class, $foreign);
        $this->assertSame(403, $foreign->getStatusCode());
        $this->assertInstanceOf(ServerRequestInterface::class, $server->handshake(new ServerRequest('GET', '/graphql', ['Origin' => 'https://app.example'])));
        // Non-browser clients do not have to send Origin.
        $this->assertInstanceOf(ServerRequestInterface::class, $server->handshake(new ServerRequest('GET', '/graphql')));
    }

    private function builder(): TypicBuilder
    {
        return Typic::builder()->registerResolvers([WhoAmIResolver::class]);
    }

    private function query(string $query, ?string $token = null): ServerRequest
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return new ServerRequest('POST', '/graphql', $headers, (string) json_encode(['query' => $query]));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true);
    }
}

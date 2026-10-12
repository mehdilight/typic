<?php

declare(strict_types=1);

namespace Typic\Tests;

use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Typic\Server\TypicBuilder;
use Typic\Tests\Fixtures\Live\LiveResolver;
use Typic\Tests\Fixtures\Live\LiveMessage;
use Typic\Typic;

class HttpSecurityTest extends TestCase
{
    public function test_default_cors_allows_any_origin_without_credentials(): void
    {
        $server = $this->builder()->build();

        $response = $server->handle($this->post('{ ping }', ['Origin' => 'https://evil.example']));
        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));

        $preflight = $server->handle($this->preflight('https://evil.example'));
        $this->assertSame(204, $preflight->getStatusCode());
        $this->assertSame('*', $preflight->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertFalse($preflight->hasHeader('Access-Control-Allow-Credentials'));
        $this->assertSame('content-type, x-token', $preflight->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function test_listed_origins_with_credentials(): void
    {
        $server = $this->builder()->withCors(origins: ['https://app.example'], credentials: true)->build();

        $allowed = $server->handle($this->post('{ ping }', ['Origin' => 'https://app.example']));
        $this->assertSame('https://app.example', $allowed->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $allowed->getHeaderLine('Access-Control-Allow-Credentials'));
        $this->assertSame('Origin', $allowed->getHeaderLine('Vary'));

        $other = $server->handle($this->post('{ ping }', ['Origin' => 'https://evil.example']));
        $this->assertFalse($other->hasHeader('Access-Control-Allow-Origin'));
        $this->assertFalse($other->hasHeader('Access-Control-Allow-Credentials'));

        $preflight = $server->handle($this->preflight('https://evil.example'));
        $this->assertFalse($preflight->hasHeader('Access-Control-Allow-Origin'));
    }

    public function test_credentials_need_listed_origins(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CORS credentials need a list of allowed origins');

        $this->builder()->withCors(credentials: true);
    }

    public function test_cors_can_be_turned_off(): void
    {
        $response = $this->builder()->withCors(false)->build()->handle($this->post('{ ping }', ['Origin' => 'https://app.example']));

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function test_mutations_are_refused_over_get(): void
    {
        $server = $this->builder()->build();

        $response = $server->handle(new ServerRequest('GET', '/graphql?' . http_build_query([
            'query' => 'mutation { sendMessage(text: "csrf") { id } }',
        ])));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
        $this->assertSame('Mutations must be sent with POST.', $this->json($response)['errors'][0]['message']);
    }

    public function test_queries_still_work_over_get(): void
    {
        $response = $this->builder()->build()->handle(new ServerRequest('GET', '/graphql?query=' . rawurlencode('{ ping }')));

        $this->assertSame(['data' => ['ping' => 'pong']], $this->json($response));
    }

    private function builder(): TypicBuilder
    {
        return Typic::builder()->registerResolvers([LiveResolver::class])->registerTypes([LiveMessage::class]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(string $query, array $headers = []): ServerRequest
    {
        return new ServerRequest('POST', '/graphql', ['Content-Type' => 'application/json'] + $headers, (string) json_encode(['query' => $query]));
    }

    private function preflight(string $origin): ServerRequest
    {
        return new ServerRequest('OPTIONS', '/graphql', [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type, x-token',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true);
    }
    public function test_simple_content_type_cannot_bypass_csrf_by_containing_json(): void
    {
        $server = \Typic\Typic::builder()->registerResolvers([\Typic\Tests\Fixtures\Library\LibraryResolver::class])->build();
        $response = $server->handle(new \Nyholm\Psr7\ServerRequest('POST', '/graphql',
            ['Content-Type' => 'text/plain; json=1'], '{"query":"{ __typename }"}'));
        $this->assertSame(400, $response->getStatusCode());
    }

}

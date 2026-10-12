<?php

declare(strict_types=1);

namespace Typic\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Typic\Attribute\Query;
use Typic\Explorer\AltairRenderer;
use Typic\Explorer\ApolloSandboxRenderer;
use Typic\Explorer\ExplorerType;
use Typic\Explorer\GraphiQLRenderer;
use Typic\Typic;

class DummyQueryResolver
{
    #[Query]
    public function ping(): string
    {
        return 'pong';
    }
}

class ExplorerTest extends TestCase
{
    public function test_apollo_sandbox_renderer(): void
    {
        $renderer = new ApolloSandboxRenderer();
        $html = $renderer->render('/graphql', [
            'title' => 'Custom Apollo',
            'subscriptionEndpoint' => 'ws://localhost:8080',
        ]);

        $this->assertStringContainsString('Custom Apollo', $html);
        $this->assertStringContainsString('embeddable-sandbox.cdn.apollographql.com', $html);
        $this->assertStringContainsString('EmbeddedSandbox', $html);
        $this->assertStringContainsString('ws://localhost:8080', $html);
    }

    public function test_graphiql_renderer(): void
    {
        $renderer = new GraphiQLRenderer();
        $html = $renderer->render('/graphql', [
            'title' => 'My API GraphiQL',
            'subscriptionEndpoint' => 'ws://localhost:8080',
            'initialDocument' => 'query Ping { ping }',
        ]);

        $this->assertStringContainsString('My API GraphiQL', $html);
        $this->assertStringContainsString('<div id="graphiql">', $html);
        $this->assertStringContainsString('graphiql.min.js', $html);
        $this->assertStringContainsString('createFetcher', $html);
        $this->assertStringContainsString('subscriptionUrl', $html);
        $this->assertStringContainsString('ws://localhost:8080', $html);
        $this->assertStringContainsString('query Ping { ping }', $html);
        // Ensure no iframe is used
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_altair_renderer(): void
    {
        $renderer = new AltairRenderer();
        $html = $renderer->render('/graphql', [
            'title' => 'My API Altair',
            'subscriptionEndpoint' => 'ws://localhost:8080',
            'initialDocument' => 'subscription OnEvent { test }',
        ]);

        $this->assertStringContainsString('My API Altair', $html);
        $this->assertStringContainsString('<app-root>', $html);
        $this->assertStringContainsString('altair-static', $html);
        // Altair only boots through AltairGraphQL.init(); global option variables are ignored
        $this->assertStringContainsString('window.AltairGraphQL.init(options)', $html);
        $this->assertStringNotContainsString('__ALTAIR_INITIAL_OPTIONS__', $html);
        $this->assertStringContainsString('ws:\/\/localhost:8080', json_encode($html) ?: '');
        $this->assertStringContainsString("initialSubscriptionRequestHandlerId = 'graphql-ws'", $html);
        $this->assertStringContainsString('subscription OnEvent { test }', $html);
        // Ensure no iframe is used
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_typic_builder_with_graphiql(): void
    {
        $server = Typic::builder()
            ->registerResolver(new DummyQueryResolver())
            ->withGraphiQL(true, endpoint: '/api/graphql', options: [
                'title' => 'GraphiQL IDE',
                'subscriptionEndpoint' => 'ws://localhost:8080',
            ])
            ->build();

        $this->assertInstanceOf(GraphiQLRenderer::class, $server->getExplorer());

        $request = new ServerRequest('GET', '/api/graphql', ['Accept' => 'text/html']);
        $response = $server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('GraphiQL IDE', $body);
        $this->assertStringContainsString('<div id="graphiql">', $body);
        $this->assertStringNotContainsString('<iframe', $body);
    }

    public function test_typic_builder_with_altair(): void
    {
        $server = Typic::builder()
            ->registerResolver(new DummyQueryResolver())
            ->withAltair(true, endpoint: '/api/graphql', options: [
                'title' => 'Altair Client',
                'subscriptionEndpoint' => 'ws://localhost:8080',
            ])
            ->build();

        $this->assertInstanceOf(AltairRenderer::class, $server->getExplorer());

        $request = new ServerRequest('GET', '/api/graphql', ['Accept' => 'text/html']);
        $response = $server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Altair Client', $body);
        $this->assertStringContainsString('<app-root>', $body);
        $this->assertStringNotContainsString('<iframe', $body);
    }

    public function test_typic_builder_with_explorer_enum(): void
    {
        $server = Typic::builder()
            ->registerResolver(new DummyQueryResolver())
            ->withExplorer(ExplorerType::Altair)
            ->build();

        $this->assertInstanceOf(AltairRenderer::class, $server->getExplorer());

        $server2 = Typic::builder()
            ->registerResolver(new DummyQueryResolver())
            ->withExplorer(ExplorerType::GraphiQL)
            ->build();

        $this->assertInstanceOf(GraphiQLRenderer::class, $server2->getExplorer());
    }
}

<?php

declare(strict_types=1);

namespace Typic\Server;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\FormattedError;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use Nyholm\Psr7\Response;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Typic\Context;
use Typic\Explorer\ApolloSandboxRenderer;

class TypicServer implements RequestHandlerInterface
{
    private ApolloSandboxRenderer $sandbox;

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $corsOptions
     */
    public function __construct(
        private Schema $schema,
        private ?ContainerInterface $container = null,
        private bool $sandboxEnabled = true,
        private string $sandboxEndpoint = '/graphql',
        private bool $debug = false,
        private array $options = [],
        private bool $corsEnabled = true,
        private array $corsOptions = [],
    ) {
        $this->sandbox = new ApolloSandboxRenderer();
    }

    public function getSchema(): Schema
    {
        return $this->schema;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCorsOptions(): array
    {
        return $this->corsOptions;
    }

    /**
     * PSR-15 RequestHandlerInterface implementation.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        // Handle CORS preflight OPTIONS request
        if ($this->corsEnabled && $method === 'OPTIONS') {
            return $this->handleCorsPreflight($request);
        }

        // Check if browser is requesting Apollo Sandbox HTML
        if ($method === 'GET') {
            $accept = $request->getHeaderLine('Accept');
            $queryParams = $request->getQueryParams();

            if ($this->sandboxEnabled && !isset($queryParams['query']) && (str_contains($accept, 'text/html') || $accept === '*/*' || $accept === '')) {
                /** @var array<string, mixed> $sandboxOpts */
                $sandboxOpts = $this->options['sandbox'] ?? $this->options;
                $html = $this->sandbox->render($this->sandboxEndpoint, $sandboxOpts);
                return $this->attachCorsHeaders(
                    new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html),
                    $request
                );
            }
        }

        // Parse GraphQL request params
        $params = $this->parseRequestParams($request);
        $query = $params['query'] ?? '';
        $variables = $params['variables'] ?? null;
        $operationName = $params['operationName'] ?? null;

        if ($query === '') {
            $payload = json_encode(['errors' => [['message' => 'GraphQL query is required']]]);
            return $this->attachCorsHeaders(
                new Response(400, ['Content-Type' => 'application/json; charset=UTF-8'], (string) $payload),
                $request
            );
        }

        // Build request context
        $context = new Context([
            'request' => $request,
            'container' => $this->container,
        ]);

        $result = $this->execute($query, $variables, null, $context, $operationName);
        $status = 200;

        return $this->attachCorsHeaders(
            new Response(
                $status,
                ['Content-Type' => 'application/json; charset=UTF-8'],
                (string) json_encode($result)
            ),
            $request
        );
    }

    /**
     * Direct execution method for programmatic GraphQL queries.
     *
     * @param array<string, mixed>|null $variables
     * @return array<string, mixed>
     */
    public function execute(
        string $query,
        ?array $variables = null,
        mixed $rootValue = null,
        mixed $context = null,
        ?string $operationName = null,
    ): array {
        $context ??= new Context(['container' => $this->container]);

        $debugFlag = $this->debug
            ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
            : DebugFlag::NONE;

        try {
            $executionResult = GraphQL::executeQuery(
                $this->schema,
                $query,
                $rootValue,
                $context,
                $variables,
                $operationName
            );

            return $executionResult->toArray($debugFlag);
        } catch (Throwable $e) {
            return [
                'errors' => [FormattedError::createFromException($e, $debugFlag)],
            ];
        }
    }

    /**
     * @return array{query?: string, variables?: array<string, mixed>|null, operationName?: string|null}
     */
    private function parseRequestParams(ServerRequestInterface $request): array
    {
        $method = strtoupper($request->getMethod());

        if ($method === 'GET') {
            $params = $request->getQueryParams();
            $query = isset($params['query']) && is_string($params['query']) ? $params['query'] : '';
            $variables = null;
            if (isset($params['variables'])) {
                $variables = is_string($params['variables']) ? json_decode($params['variables'], true) : $params['variables'];
            }
            $opName = isset($params['operationName']) && is_string($params['operationName']) ? $params['operationName'] : null;

            return [
                'query' => $query,
                'variables' => is_array($variables) ? $variables : null,
                'operationName' => $opName,
            ];
        }

        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            $data = json_decode($body, true);
            if (is_array($data)) {
                return [
                    'query' => isset($data['query']) && is_string($data['query']) ? $data['query'] : '',
                    'variables' => isset($data['variables']) && is_array($data['variables']) ? $data['variables'] : null,
                    'operationName' => isset($data['operationName']) && is_string($data['operationName']) ? $data['operationName'] : null,
                ];
            }
        }

        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody)) {
            $query = isset($parsedBody['query']) && is_string($parsedBody['query']) ? $parsedBody['query'] : '';
            $variables = null;
            if (isset($parsedBody['variables'])) {
                $variables = is_string($parsedBody['variables']) ? json_decode($parsedBody['variables'], true) : $parsedBody['variables'];
            }
            $opName = isset($parsedBody['operationName']) && is_string($parsedBody['operationName']) ? $parsedBody['operationName'] : null;

            return [
                'query' => $query,
                'variables' => is_array($variables) ? $variables : null,
                'operationName' => $opName,
            ];
        }

        return [];
    }

    private function handleCorsPreflight(ServerRequestInterface $request): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allowOrigin = is_string($this->corsOptions['origin'] ?? null)
            ? $this->corsOptions['origin']
            : ($origin !== '' ? $origin : '*');

        $requestHeaders = $request->getHeaderLine('Access-Control-Request-Headers');
        $allowHeaders = is_string($this->corsOptions['headers'] ?? null)
            ? $this->corsOptions['headers']
            : ($requestHeaders !== ''
                ? $requestHeaders
                : 'Content-Type, Authorization, X-Requested-With, Apollo-Require-Preflight, X-Apollo-Operation-Name');

        $headers = [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => $allowHeaders,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400',
            'Content-Length' => '0',
        ];

        return new Response(204, $headers);
    }

    private function attachCorsHeaders(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->corsEnabled) {
            return $response;
        }

        $origin = $request->getHeaderLine('Origin');
        $allowOrigin = is_string($this->corsOptions['origin'] ?? null)
            ? $this->corsOptions['origin']
            : ($origin !== '' ? $origin : '*');

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Expose-Headers', '*');
    }
}


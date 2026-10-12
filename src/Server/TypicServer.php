<?php

declare(strict_types=1);

namespace Typic\Server;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\FormattedError;
use GraphQL\Error\SyntaxError;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Utils\AST;
use GraphQL\Type\Schema;
use LogicException;
use Nyholm\Psr7\Response;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Typic\Context;
use Typic\Explorer\ApolloSandboxRenderer;
use Typic\Explorer\ExplorerInterface;
use Typic\Explorer\ExplorerType;
use Typic\Subscription\PubSub\PubSubInterface;
use Typic\Subscription\SubscriptionServer;

class TypicServer implements RequestHandlerInterface
{
    private ExplorerInterface $explorer;
    private ErrorReporter $errors;

    /**
     * @param array<string, mixed> $options
     * @param list<MiddlewareInterface> $middleware PSR-15 middleware around every request, first outermost
     */
    public function __construct(
        private Schema $schema,
        private ?ContainerInterface $container = null,
        private bool $sandboxEnabled = true,
        private string $sandboxEndpoint = '/graphql',
        private bool $debug = false,
        private array $options = [],
        private ?Cors $cors = null,
        private ?SubscriptionServer $subscriptionServer = null,
        private ?PubSubInterface $pubSub = null,
        ?ExplorerInterface $explorer = null,
        private array $middleware = [],
        private ?QueryLimits $limits = null,
        private ?int $maxRequestSize = null,
        ?ErrorReporter $errors = null,
        private bool $csrfProtection = true,
    ) {
        $this->errors = $errors ?? new ErrorReporter();
        $this->pubSub ??= $this->subscriptionServer?->getPubSub();
        $this->explorer = $explorer ?? new ApolloSandboxRenderer();
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

    public function getSubscriptionServer(): ?SubscriptionServer
    {
        return $this->subscriptionServer;
    }

    public function getPubSub(): ?PubSubInterface
    {
        return $this->pubSub ?? $this->subscriptionServer?->getPubSub();
    }

    public function getExplorer(): ExplorerInterface
    {
        return $this->explorer;
    }

    /**
     * Serve HTTP and WebSocket traffic from the subscription driver's event loop. Blocks until stopped.
     */
    public function serve(): void
    {
        if ($this->subscriptionServer === null) {
            throw new LogicException('TypicServer::serve() requires subscriptions: call withSubscriptions() on the builder.');
        }

        $this->subscriptionServer->start();
    }

    /**
     * PSR-15 RequestHandlerInterface implementation.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->cors !== null && strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->cors->preflight($request);
        }

        $size = $request->getBody()->getSize() ?? strlen((string) $request->getBody());
        try {
            $response = $this->maxRequestSize !== null && $size > $this->maxRequestSize
                ? $this->json(413, ['errors' => [['message' => "The request is larger than {$this->maxRequestSize} bytes."]]])
                : (new MiddlewarePipeline($this->middleware, $this->handleGraphQL(...)))->handle($request);
        } catch (Throwable $e) {
            $this->errors->exception($e, 'HTTP request');
            $response = $this->json(500, ['errors' => [['message' => 'Internal server error']]]);
        }

        return $this->cors?->apply($response, $request) ?? $response;
    }

    /**
     * Run a WebSocket upgrade request through the HTTP middleware.
     *
     * @return ServerRequestInterface|ResponseInterface The request as the middleware left it (with
     *         its attributes), or the response a middleware answered with to refuse the upgrade
     */
    public function handshake(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && $this->cors !== null && !$this->cors->allowsOrigin($origin)) {
            return $this->json(403, ['errors' => [['message' => 'WebSocket origin is not allowed.']]]);
        }
        $accepted = null;
        try {
            $response = (new MiddlewarePipeline($this->middleware, static function (ServerRequestInterface $request) use (&$accepted): ResponseInterface {
                $accepted = $request;
                return new Response(101);
            }))->handle($request);
        } catch (Throwable $e) {
            $this->errors->exception($e, 'WebSocket handshake');
            return $this->json(500, ['errors' => [['message' => 'Internal server error']]]);
        }

        return $accepted instanceof ServerRequestInterface && $response->getStatusCode() === 101 ? $accepted : $response;
    }

    private function handleGraphQL(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        if ($method === 'GET' && $this->wantsExplorer($request)) {
            /** @var array<string, mixed> $sandboxOpts */
            $sandboxOpts = $this->options['sandbox'] ?? $this->options;
            // ?explorer=graphiql|altair|apollo switches the explorer for this request
            $requested = $request->getQueryParams()['explorer'] ?? null;
            $type = is_string($requested) ? ExplorerType::tryFrom(strtolower($requested)) : null;
            $explorer = $type?->createRenderer() ?? $this->explorer;

            return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $explorer->render($this->sandboxEndpoint, $sandboxOpts));
        }

        try {
            $params = $this->parseRequestParams($request);
        } catch (BadRequest $e) {
            return $this->json(400, ['errors' => [['message' => $e->getMessage()]]]);
        }
        $query = $params['query'] ?? '';
        $operationName = $params['operationName'] ?? null;

        if ($query === '') {
            return $this->json(400, ['errors' => [['message' => 'GraphQL query is required']]]);
        }

        try {
            $document = Parser::parse($query);
        } catch (SyntaxError $e) {
            return $this->json(200, ['errors' => [FormattedError::createFromException($e)]]);
        }

        // A GET request can be triggered by any website (a link, an <img>), so it must not change anything
        if ($method === 'GET' && AST::getOperationAST($document, $operationName)?->operation === 'mutation') {
            return $this->json(405, ['errors' => [['message' => 'Mutations must be sent with POST.']]])->withHeader('Allow', 'POST');
        }

        $context = new Context([
            'request' => $request,
            'container' => $this->container,
            'pubSub' => $this->pubSub,
        ]);

        return $this->json(200, $this->execute($document, $params['variables'] ?? null, null, $context, $operationName));
    }

    private function wantsExplorer(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');

        return $this->sandboxEnabled
            && !isset($request->getQueryParams()['query'])
            && (str_contains($accept, 'text/html') || $accept === '*/*' || $accept === '');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=UTF-8'], (string) json_encode($body));
    }

    /**
     * Direct execution method for programmatic GraphQL queries.
     *
     * @param array<string, mixed>|null $variables
     * @return array<string, mixed>
     */
    public function execute(
        string|DocumentNode $query,
        ?array $variables = null,
        mixed $rootValue = null,
        mixed $context = null,
        ?string $operationName = null,
    ): array {
        $context ??= new Context([
            'container' => $this->container,
            'pubSub' => $this->pubSub,
        ]);

        $debugFlag = $this->debug
            ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
            : DebugFlag::NONE;

        try {
            $document = is_string($query) ? Parser::parse($query) : $query;
        } catch (SyntaxError $e) {
            return ['errors' => [FormattedError::createFromException($e, $debugFlag)]];
        }

        try {
            $executionResult = GraphQL::executeQuery(
                $this->schema,
                $document,
                $rootValue,
                $context,
                $variables,
                $operationName,
                null,
                $this->limits?->rules(),
            );
            $this->errors->report($executionResult->errors, $operationName ?? AST::getOperationAST($document, null)?->name?->value);

            return $executionResult->toArray($debugFlag);
        } catch (Throwable $e) {
            $this->errors->exception($e, 'GraphQL execution');
            return [
                'errors' => [FormattedError::createFromException($e, $debugFlag)],
            ];
        }
    }

    /**
     * @return array{query?: string, variables?: array<string, mixed>|null, operationName?: string|null}
     * @throws BadRequest
     */
    private function parseRequestParams(ServerRequestInterface $request): array
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            return self::operation($request->getQueryParams());
        }

        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        $mediaType = trim(explode(';', $contentType, 2)[0]);
        if ($mediaType === 'application/json' || (str_starts_with($mediaType, 'application/') && str_ends_with($mediaType, '+json'))) {
            $data = json_decode((string) $request->getBody(), true);
            return is_array($data) ? self::operation($data) : [];
        }

        // Browsers send these content types across sites without asking first, so a page on
        // another site could post a mutation with your users' cookies. A custom header makes
        // the browser ask (CORS preflight), which only allowed origins pass.
        if ($this->csrfProtection && !$this->hasPreflightHeader($request)) {
            throw new BadRequest(
                'This request needs a GraphQL-Require-Preflight header (Apollo-Require-Preflight also works). '
                . 'It protects your users from cross-site request forgery.',
            );
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return [];
        }

        return str_contains($contentType, 'multipart/form-data')
            ? self::multipartOperation($body, $request->getUploadedFiles())
            : self::operation($body);
    }

    private function hasPreflightHeader(ServerRequestInterface $request): bool
    {
        foreach (['GraphQL-Require-Preflight', 'Apollo-Require-Preflight', 'X-Apollo-Operation-Name'] as $header) {
            if ($request->getHeaderLine($header) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $data
     * @return array{query: string, variables: array<string, mixed>|null, operationName: string|null}
     */
    private static function operation(array $data): array
    {
        $variables = $data['variables'] ?? null;
        if (is_string($variables)) {
            $variables = json_decode($variables, true);
        }

        /** @var array<string, mixed>|null $variables */
        return [
            'query' => isset($data['query']) && is_string($data['query']) ? $data['query'] : '',
            'variables' => is_array($variables) ? $variables : null,
            'operationName' => isset($data['operationName']) && is_string($data['operationName']) ? $data['operationName'] : null,
        ];
    }

    /**
     * The GraphQL multipart request spec: "operations" holds the request with nulls where
     * files go, and "map" says which uploaded file goes where.
     *
     * @param array<mixed> $body
     * @param array<mixed> $files
     * @return array{query: string, variables: array<string, mixed>|null, operationName: string|null}
     * @throws BadRequest
     */
    private static function multipartOperation(array $body, array $files): array
    {
        $operations = json_decode(is_string($body['operations'] ?? null) ? $body['operations'] : '', true);
        if (!is_array($operations) || $operations === []) {
            throw new BadRequest('The "operations" field must be a JSON object.');
        }
        if (array_is_list($operations)) {
            throw new BadRequest('Batched operations are not supported.');
        }

        $map = json_decode(is_string($body['map'] ?? null) ? $body['map'] : '{}', true);
        if (!is_array($map)) {
            throw new BadRequest('The "map" field must be a JSON object.');
        }

        foreach ($map as $key => $paths) {
            $file = $files[$key] ?? null;
            if (!$file instanceof UploadedFileInterface) {
                throw new BadRequest(sprintf('File "%s" is listed in the map but was not uploaded.', $key));
            }
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new BadRequest(sprintf('File "%s" failed to upload (PHP upload error %d).', $key, $file->getError()));
            }

            if (!is_array($paths) || !array_is_list($paths) || $paths === []) {
                throw new BadRequest('Each file map entry must be a non-empty list of variable paths.');
            }
            foreach ($paths as $path) {
                if (!is_string($path)) {
                    throw new BadRequest('File map paths must be strings.');
                }
                $parts = explode('.', $path);
                if (array_shift($parts) !== 'variables' || $parts === []) {
                    throw new BadRequest(sprintf('The map can only place files under "variables", got "%s".', $path));
                }

                $slot = &$operations['variables'];
                foreach ($parts as $part) {
                    if (!is_array($slot) || !array_key_exists($part, $slot)) {
                        throw new BadRequest(sprintf('The file map path "%s" does not exist in operations.', $path));
                    }
                    $slot = &$slot[$part];
                }
                if ($slot !== null) {
                    throw new BadRequest(sprintf('The file map path "%s" must point to a null placeholder.', $path));
                }
                $slot = $file;
                unset($slot);
            }
        }

        return self::operation($operations);
    }

}

<?php

declare(strict_types=1);

namespace Typic\Server;

use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Cross-origin rules for browsers.
 *
 * The default lets any site call the API without credentials (cookies, HTTP auth): safe,
 * since a page on another site cannot act as your logged-in users. To use cookies, list the
 * sites allowed to send them.
 */
final class Cors
{
    /** @var list<string>|null Null means any origin */
    private ?array $origins;

    /**
     * @param list<string>|string $origins '*' for any origin, one origin, or a list
     */
    public function __construct(
        array|string $origins = '*',
        private bool $credentials = false,
        private ?string $allowHeaders = null,
        private int $maxAge = 86400,
    ) {
        $this->origins = match (true) {
            $origins === '*' => null,
            is_string($origins) => [$origins],
            default => $origins,
        };

        if ($credentials && $this->origins === null) {
            throw new InvalidArgumentException(
                "CORS credentials need a list of allowed origins: withCors(origins: ['https://app.example.com'], credentials: true). "
                . 'Allowing credentials from any origin would let every website act as your logged-in users.',
            );
        }
    }

    public function allowsOrigin(string $origin): bool
    {
        return $this->origins === null || in_array($origin, $this->origins, true);
    }

    public function preflight(ServerRequestInterface $request): ResponseInterface
    {
        $requested = $request->getHeaderLine('Access-Control-Request-Headers');

        return $this->apply(new Response(204, [
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => $this->allowHeaders ?? ($requested !== '' ? $requested : 'Content-Type, Authorization'),
            'Access-Control-Max-Age' => (string) $this->maxAge,
            'Content-Length' => '0',
        ]), $request);
    }

    public function apply(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        if ($this->origins === null) {
            return $response->withHeader('Access-Control-Allow-Origin', '*');
        }

        // The answer depends on the Origin header, so caches must keep one copy per origin
        $response = $response->withAddedHeader('Vary', 'Origin');
        $origin = $request->getHeaderLine('Origin');
        if (!$this->allowsOrigin($origin)) {
            return $response;
        }

        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);

        return $this->credentials ? $response->withHeader('Access-Control-Allow-Credentials', 'true') : $response;
    }
}

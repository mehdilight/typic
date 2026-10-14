<?php

declare(strict_types=1);

namespace Typic\Validation;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use RuntimeException;

/**
 * Input broke one or more rules. Clients get every problem in extensions.violations, in the order found:
 * [{"path": "input.email", "code": "INVALID_EMAIL", "message": "must be a valid email address", "params": {}}].
 */
final class ValidationError extends RuntimeException implements ClientAware, ProvidesExtensions
{
    /**
     * @param list<array{path: string, violation: Violation}> $violations
     */
    public function __construct(private array $violations)
    {
        $problems = array_map(static fn (array $item): string => "{$item['path']} {$item['violation']->message}", $violations);
        $shown = array_slice($problems, 0, 2);
        $more = count($problems) - count($shown);
        parent::__construct('Invalid input: ' . implode('; ', $shown) . ($more > 0 ? "; and {$more} more." : '.'));
    }

    /**
     * @return list<array{path: string, violation: Violation}>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    public function getExtensions(): array
    {
        return ['violations' => array_map(static fn (array $item): array => [
            'path' => $item['path'],
            'code' => $item['violation']->code,
            'message' => $item['violation']->message,
            'params' => (object) $item['violation']->params,
        ], $this->violations)];
    }
}

<?php

declare(strict_types=1);

namespace Typic\Validation;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use RuntimeException;

/**
 * Input broke one or more rules. Clients get every problem in extensions.validation,
 * keyed by the path of the field, e.g. {"input.email": ["must be a valid email address"]}.
 */
final class ValidationError extends RuntimeException implements ClientAware, ProvidesExtensions
{
    /**
     * @param array<string, list<string>> $errors Path => problems
     */
    public function __construct(private array $errors)
    {
        $problems = [];
        foreach ($errors as $path => $messages) {
            foreach ($messages as $message) {
                $problems[] = "{$path} {$message}";
            }
        }

        $shown = array_slice($problems, 0, 2);
        $more = count($problems) - count($shown);
        parent::__construct('Invalid input: ' . implode('; ', $shown) . ($more > 0 ? "; and {$more} more." : '.'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    public function getExtensions(): array
    {
        return ['validation' => $this->errors];
    }
}

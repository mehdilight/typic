<?php

declare(strict_types=1);

namespace Typic\Server;

use GraphQL\Error\Error;
use Psr\Log\LoggerInterface;
use Throwable;
use Typic\Subscription\SubscriptionSignal;

/**
 * Logs errors the client cannot be told about (bugs, outages), with the original exception.
 * Errors meant for clients (UserError, validation, bad queries) are not logged.
 */
final class ErrorReporter
{
    public function __construct(private ?LoggerInterface $logger = null)
    {
    }

    /**
     * @param array<Error> $errors
     */
    public function report(array $errors, ?string $operation = null): void
    {
        if ($this->logger === null) {
            return;
        }

        foreach ($errors as $error) {
            $original = $error->getPrevious();
            if ($error->isClientSafe() || $original instanceof SubscriptionSignal) {
                continue;
            }

            $path = implode('.', $error->getPath() ?? []);
            $this->logger->error(
                sprintf('GraphQL error in %s: %s', $path !== '' ? $path : 'request', ($original ?? $error)->getMessage()),
                ['exception' => $original ?? $error, 'path' => $error->getPath(), 'operation' => $operation],
            );
        }
    }

    public function exception(Throwable $exception, string $where): void
    {
        $this->logger?->error(
            sprintf('Unhandled error in %s: %s', $where, $exception->getMessage()),
            ['exception' => $exception],
        );
    }
}

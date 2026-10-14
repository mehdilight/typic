<?php

declare(strict_types=1);

namespace Typic\Validation;

/**
 * One broken rule: a stable code for clients to translate, a readable English message, and the values
 * the message mentions. A rule's custom message replaces the text but keeps the code.
 *
 *     return new Violation('TOO_LONG', 'must be at most 20 characters', ['max' => 20]);
 */
final class Violation
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $params = [],
    ) {
    }
}

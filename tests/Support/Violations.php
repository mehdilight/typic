<?php

declare(strict_types=1);

namespace Typic\Tests\Support;

/** Reads a GraphQL error's validation violations as path => messages, for compact assertions. */
final class Violations
{
    /**
     * @param array<string, mixed> $error One entry of a result's "errors"
     * @return array<string, list<string>>|null
     */
    public static function messages(array $error): ?array
    {
        $violations = $error['extensions']['violations'] ?? null;
        if (!is_array($violations)) {
            return null;
        }
        $messages = [];
        foreach ($violations as $violation) {
            $messages[$violation['path']][] = $violation['message'];
        }

        return $messages;
    }
}

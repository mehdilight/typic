<?php

declare(strict_types=1);

namespace Typic\Scalar;

/**
 * A value object that is a GraphQL scalar. Mark the class with #[Scalar]:
 *
 *     #[Scalar(description: 'An email address')]
 *     final class Email implements ScalarValue
 *     {
 *         public static function parse(mixed $value): static { ... }   // throw to refuse
 *         public function serialize(): string { return $this->address; }
 *     }
 *
 * Then type arguments, input fields and output fields as Email.
 */
interface ScalarValue
{
    /**
     * Build the value from what the client sent. Throw to refuse it; the message is shown to the client.
     */
    public static function parse(mixed $value): static;

    /** What the client receives. */
    public function serialize(): mixed;
}

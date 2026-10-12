<?php

declare(strict_types=1);

namespace Typic\Scalar;

/**
 * A custom scalar for a PHP type, registered with TypicBuilder::withScalar(SomeClass::class, new SomeScalar()).
 *
 * Use it for classes you cannot add attributes to (from other packages). For your own value
 * objects, #[Scalar] with ScalarValue is shorter.
 */
interface ScalarType
{
    /** The scalar's name in the schema, e.g. "UUID". */
    public function name(): string;

    public function description(): ?string;

    /** Turn a PHP value returned by a resolver into what the client receives. */
    public function serialize(mixed $value): mixed;

    /**
     * Turn what the client sent (from variables or written in the query) into the PHP value
     * resolvers receive. Throw to refuse it; the message is shown to the client.
     */
    public function parse(mixed $value): mixed;
}

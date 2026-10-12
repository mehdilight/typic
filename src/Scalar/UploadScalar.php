<?php

declare(strict_types=1);

namespace Typic\Scalar;

use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Upload: a file sent with the GraphQL multipart request spec
 * (https://github.com/jaydenseric/graphql-multipart-request-spec). Type an argument or input
 * field as Psr\Http\Message\UploadedFileInterface to receive one.
 */
final class UploadScalar implements ScalarType
{
    public function name(): string
    {
        return 'Upload';
    }

    public function description(): string
    {
        return 'A file, sent as part of a multipart/form-data request.';
    }

    public function serialize(mixed $value): never
    {
        throw new LogicException('Upload is for input only: fields cannot return it.');
    }

    public function parse(mixed $value): UploadedFileInterface
    {
        if (!$value instanceof UploadedFileInterface) {
            throw new InvalidArgumentException('Upload values must be sent as files in a multipart request (GraphQL multipart request spec), not written in the query.');
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;
use finfo;
use Psr\Http\Message\UploadedFileInterface;

/**
 * An uploaded file of one of the given types, e.g. #[MimeType('image/png', 'image/jpeg')] or
 * #[MimeType('image/*')]. The type is read from the file's content, not from what the client
 * claims, so a script renamed to .png is refused.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class MimeType implements Rule
{
    /** @var list<string> */
    private array $types;

    public function __construct(string ...$types)
    {
        $this->types = array_values($types);
    }

    public function check(mixed $value): ?Violation
    {
        if (!$value instanceof UploadedFileInterface) {
            return null;
        }

        $type = self::detect($value);
        foreach ($this->types as $allowed) {
            $matches = str_ends_with($allowed, '/*')
                ? str_starts_with((string) $type, substr($allowed, 0, -1))
                : $type === $allowed;
            if ($matches) {
                return null;
            }
        }

        return new Violation('INVALID_FILE_TYPE', 'must be a file of type ' . implode(', ', $this->types), ['types' => $this->types]);
    }

    private static function detect(UploadedFileInterface $file): ?string
    {
        if (!class_exists(finfo::class)) {
            throw new \LogicException('MimeType validation requires the PHP fileinfo extension.');
        }

        $stream = $file->getStream();
        $stream->rewind();
        $head = $stream->read(8192);
        $stream->rewind();

        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return is_string($type) ? $type : null;
    }
}

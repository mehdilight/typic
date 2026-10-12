<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;
use Psr\Http\Message\UploadedFileInterface;

/**
 * An uploaded file of at most $bytes.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class MaxFileSize implements Rule
{
    public function __construct(
        private int $bytes,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value): ?string
    {
        if (!$value instanceof UploadedFileInterface) {
            return null;
        }

        return ($value->getSize() ?? 0) <= $this->bytes ? null : ($this->message ?? 'must be at most ' . self::describe($this->bytes));
    }

    private static function describe(int $bytes): string
    {
        foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $unit => $size) {
            if ($bytes >= $size) {
                return rtrim(rtrim(number_format($bytes / $size, 1, '.', ''), '0'), '.') . " {$unit}";
            }
        }

        return "{$bytes} bytes";
    }
}

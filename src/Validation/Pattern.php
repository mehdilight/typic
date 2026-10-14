<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;
use InvalidArgumentException;

/**
 * A string matching a regular expression, e.g. #[Pattern('/^[a-z0-9-]+$/')].
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Pattern implements Rule
{
    public function __construct(
        private string $regex,
        private ?string $message = null,
    ) {
        // A broken pattern fails when the schema is built, not on the first request that uses it
        if (@preg_match($regex, '') === false) {
            throw new InvalidArgumentException("Invalid regular expression {$regex}: " . preg_last_error_msg());
        }
    }

    public function check(mixed $value): ?Violation
    {
        if (is_string($value) && preg_match($this->regex, $value) === 1) {
            return null;
        }

        return new Violation('INVALID_FORMAT', $this->message ?? 'has an invalid format');
    }
}

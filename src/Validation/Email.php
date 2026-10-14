<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * An email address. International addresses are accepted unless $unicode is false.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Email implements Rule
{
    public function __construct(
        private bool $unicode = true,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value): ?Violation
    {
        $flags = $this->unicode ? FILTER_FLAG_EMAIL_UNICODE : 0;
        // Line breaks would let a value inject extra headers into an email
        $valid = is_string($value)
            && preg_match('/[\r\n]/', $value) !== 1
            && filter_var($value, FILTER_VALIDATE_EMAIL, $flags) !== false;

        return $valid ? null : new Violation('INVALID_EMAIL', $this->message ?? 'must be a valid email address');
    }
}

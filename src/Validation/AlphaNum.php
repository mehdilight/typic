<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * Only letters and digits. Letters from any language unless $ascii is true.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class AlphaNum implements Rule
{
    public function __construct(
        private bool $ascii = false,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value): ?Violation
    {
        $pattern = $this->ascii ? '/\\A[a-zA-Z0-9]+\\z/u' : '/\\A[\\pL\\pM\\pN]+\\z/u';

        return is_string($value) && preg_match($pattern, $value) === 1 ? null : new Violation('ALPHA_NUM', $this->message ?? 'must contain only letters and numbers');
    }
}

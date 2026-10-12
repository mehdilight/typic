<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Ip implements Rule
{
    /**
     * @param 4|6|null $version Only IPv4 or only IPv6; both by default
     */
    public function __construct(
        private ?int $version = null,
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value): ?string
    {
        $flags = match ($this->version) {
            4 => FILTER_FLAG_IPV4,
            6 => FILTER_FLAG_IPV6,
            default => 0,
        };
        $label = $this->version !== null ? "IPv{$this->version} address" : 'IP address';

        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP, $flags) !== false ? null : ($this->message ?? "must be a valid {$label}");
    }
}

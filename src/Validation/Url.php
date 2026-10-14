<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * A URL with one of the given schemes (http and https by default). International domain
 * names, IP addresses, ports, paths, queries and fragments are accepted.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Url implements Rule
{
    /*
     * Derived from Symfony's UrlValidator (MIT, (c) Fabien Potencier), as Laravel's Str::isUrl() is.
     */
    private const PATTERN = '~^
        (%s)://                                                         # scheme
        (((?:[\_\.\pL\pN-]|%%[0-9A-Fa-f]{2})+:)?((?:[\_\.\pL\pN-]|%%[0-9A-Fa-f]{2})+)@)?   # user:password@
        (
            (?:
                (?:(?:[\pL\pN\pS\pM\-\_]++\.)+(?:(?:xn--[a-z0-9-]++)|(?:[\pL\pN\pM]++)))  # domain
                |
                [a-z0-9\-\_]++                                       # single-level host, e.g. localhost
            )\.?
            |
            \d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}                    # IPv4
            |
            \[[0-9a-f:.]+\]                                         # IPv6, checked again below
        )
        (:[0-9]+)?                                                    # port
        (?:/ (?:[\pL\pN\-._\~!$&\'()*+,;=:@]|%%[0-9A-Fa-f]{2})* )*        # path
        (?:\? (?:[\pL\pN\-._\~!$&\'\[\]()*+,;=:@/?]|%%[0-9A-Fa-f]{2})* )?  # query
        (?:\# (?:[\pL\pN\-._\~!$&\'()*+,;=:@/?]|%%[0-9A-Fa-f]{2})* )?      # fragment
    $~ixu';

    /**
     * @param list<string> $schemes
     */
    public function __construct(
        private array $schemes = ['http', 'https'],
        private ?string $message = null,
    ) {
    }

    public function check(mixed $value): ?Violation
    {
        return is_string($value) && $this->isUrl($value) ? null : new Violation('INVALID_URL', $this->message ?? 'must be a valid URL');
    }

    private function isUrl(string $value): bool
    {
        $schemes = implode('|', array_map(static fn (string $scheme): string => preg_quote($scheme, '~'), $this->schemes));
        if (preg_match(sprintf(self::PATTERN, $schemes), $value) !== 1) {
            return false;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (is_string($host) && str_starts_with($host, '[')) {
            return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        if (is_string($host) && preg_match('/^[\d.]+$/', $host) === 1) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        return true;
    }
}

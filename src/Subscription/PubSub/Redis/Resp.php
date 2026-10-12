<?php

declare(strict_types=1);

namespace Typic\Subscription\PubSub\Redis;

use UnexpectedValueException;

/**
 * Minimal RESP2 encoder and parser: enough for PUBLISH, SUBSCRIBE and their replies.
 */
final class Resp
{
    /**
     * @param list<string> $args
     */
    public static function encode(array $args): string
    {
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $out .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        return $out;
    }

    /**
     * Parse one reply starting at $offset.
     *
     * @return array{0: mixed, 1: int}|null The reply and the offset just past it, or null while the buffer holds no complete reply
     */
    public static function parse(string $buffer, int $offset = 0): ?array
    {
        $lineEnd = strpos($buffer, "\r\n", $offset);
        if ($lineEnd === false) {
            return null;
        }

        $type = $buffer[$offset];
        $line = substr($buffer, $offset + 1, $lineEnd - $offset - 1);
        $next = $lineEnd + 2;

        switch ($type) {
            case '+':
                return [$line, $next];

            case '-':
                return [new RespError($line), $next];

            case ':':
                return [(int) $line, $next];

            case '$':
                $length = (int) $line;
                if ($length < 0) {
                    return [null, $next];
                }
                if (strlen($buffer) < $next + $length + 2) {
                    return null;
                }
                return [substr($buffer, $next, $length), $next + $length + 2];

            case '*':
                $count = (int) $line;
                if ($count < 0) {
                    return [null, $next];
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $item = self::parse($buffer, $next);
                    if ($item === null) {
                        return null;
                    }
                    [$items[], $next] = $item;
                }
                return [$items, $next];

            default:
                throw new UnexpectedValueException("Invalid RESP type byte '{$type}'");
        }
    }
}

<?php

declare(strict_types=1);

namespace Typic\Subscription\PubSub\Redis;

/**
 * An error reply ("-ERR ...") from Redis.
 */
final class RespError
{
    public function __construct(
        public readonly string $message,
    ) {
    }
}

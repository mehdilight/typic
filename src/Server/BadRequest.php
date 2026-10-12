<?php

declare(strict_types=1);

namespace Typic\Server;

use RuntimeException;

/**
 * The HTTP request is malformed; answered with 400 and the message.
 *
 * @internal
 */
final class BadRequest extends RuntimeException
{
}

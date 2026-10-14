<?php

declare(strict_types=1);

namespace Typic\Validation;

use Attribute;

/**
 * Stops checking a field at its first problem, so clients get at most one message per field
 * (and per list item, for #[Each]):
 *
 *     #[Bail] #[NotBlank] #[Length(max: 32)] #[Pattern('/^[a-z]+$/')] public string $handle
 *
 * Without it every rule runs, except that a failed NotBlank or Required* rule always ends the field's checks.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Bail
{
}

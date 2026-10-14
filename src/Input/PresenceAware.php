<?php

declare(strict_types=1);

namespace Typic\Input;

/**
 * An input object that wants to know which fields the client sent. Typic calls this after building it.
 * Use the TracksPresence trait rather than implementing it by hand.
 */
interface PresenceAware
{
    /** @param list<string> $fields PHP property names of the fields present in the request */
    public function withPresentFields(array $fields): void;
}

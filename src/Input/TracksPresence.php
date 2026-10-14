<?php

declare(strict_types=1);

namespace Typic\Input;

/**
 * Tells a left-out field from one sent as null, for partial updates: omitted means unchanged, null clears.
 *
 *     #[InputType]
 *     final class UpdateProfileInput implements PresenceAware
 *     {
 *         use TracksPresence;
 *
 *         public function __construct(#[Field(nullable: true)] public ?string $bio) {}
 *     }
 *
 *     if ($input->has('bio')) { $profile->bio = $input->bio; }
 *
 * A field with a default value always counts as present, since GraphQL fills it in. A PHP default such as
 * `= null` becomes a GraphQL default, so leave tracked fields without one.
 */
trait TracksPresence
{
    /** @var list<string> */
    private array $presentFields = [];

    /** @param list<string> $fields */
    public function withPresentFields(array $fields): void
    {
        $this->presentFields = $fields;
    }

    /** Whether the client sent this field, by PHP property name, even as null. */
    public function has(string $field): bool
    {
        return in_array($field, $this->presentFields, true);
    }

    /** @return list<string> The PHP property names of every field the client sent. */
    public function presentFields(): array
    {
        return $this->presentFields;
    }
}

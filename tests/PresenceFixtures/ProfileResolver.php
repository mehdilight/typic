<?php

declare(strict_types=1);

namespace Typic\Tests\PresenceFixtures;

use Typic\Attribute\Mutation;
use Typic\Attribute\Query;

final class ProfileResolver
{
    #[Query]
    public function ping(): string
    {
        return 'pong';
    }

    /** Reports each field as "omitted", "null" or its value, plus the nested address's present fields. */
    #[Mutation]
    public function patchProfile(ProfilePatch $input): string
    {
        $describe = static fn(string $field, ?string $value): string => $field . '=' . (!$input->has($field) ? 'omitted' : ($value ?? 'null'));

        return implode(' ', [
            $describe('name', $input->name),
            $describe('bio', $input->bio),
            $describe('homepage', $input->homepage),
            'address=' . ($input->address === null ? '-' : implode(',', $input->address->presentFields())),
        ]);
    }
}

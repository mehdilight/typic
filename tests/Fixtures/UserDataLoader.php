<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures;

use Typic\DataLoader\DataLoader;

/**
 * @extends DataLoader<int, User|null>
 */
class UserDataLoader extends DataLoader
{
    protected function batch(array $keys): iterable
    {
        $users = [
            new User(1, 'Alice', 'alice@example.com'),
            new User(2, 'Bob', 'bob@example.com'),
        ];

        return $this->keyBy(array_filter($users, fn (User $user): bool => in_array($user->id, $keys, true)), 'id');
    }
}

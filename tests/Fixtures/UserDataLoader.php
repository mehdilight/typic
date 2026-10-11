<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures;

use Typic\DataLoader\DataLoader;

/**
 * @extends DataLoader<int, User>
 */
class UserDataLoader extends DataLoader
{
    protected mixed $keyBy = 'id';

    public int $batchCallCount = 0;

    /**
     * @param list<mixed> $keys
     * @return list<User>
     */
    protected function batch(array $keys): iterable
    {
        $this->batchCallCount++;

        $users = [
            1 => new User(1, 'Alice', 'alice@example.com'),
            2 => new User(2, 'Bob', 'bob@example.com'),
        ];

        $result = [];
        foreach ($keys as $key) {
            if (isset($users[(int) $key])) {
                $result[] = $users[(int) $key];
            }
        }

        return $result;
    }
}

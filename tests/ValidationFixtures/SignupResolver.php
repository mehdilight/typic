<?php

declare(strict_types=1);

namespace Typic\Tests\ValidationFixtures;

use Typic\Attribute\Arg;
use Typic\Attribute\Mutation;
use Typic\Attribute\Query;
use Typic\Validation as Assert;

class SignupResolver
{
    public static int $calls = 0;

    #[Mutation]
    public function signup(SignupInput $input): string
    {
        self::$calls++;

        return "welcome {$input->username} ({$input->tier})";
    }

    #[Mutation]
    public function tree(RecursiveInput $input): bool
    {
        self::$calls++;
        return true;
    }

    #[Mutation]
    public function openAccount(AccountInput $input): bool
    {
        self::$calls++;

        return true;
    }

    /**
     * @return list<string>
     */
    #[Query(arrayOf: 'string')]
    public function search(
        #[Assert\Length(min: 2)] string $term,
        #[Arg(name: 'first')] #[Assert\Range(min: 1, max: 50)] int $limit = 10,
    ): array {
        self::$calls++;

        return array_fill(0, min($limit, 2), $term);
    }
}

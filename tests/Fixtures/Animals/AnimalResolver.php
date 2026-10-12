<?php

declare(strict_types=1);

namespace Typic\Tests\Fixtures\Animals;

use Typic\Attribute\Query;

class AnimalResolver
{
    /**
     * @return list<Named>
     */
    #[Query(arrayOf: Named::class)]
    public function named(): array
    {
        return [new Dog('Sam', 'Rex', 'Beagle'), new Robot(42)];
    }

    /**
     * @return list<Pet>
     */
    #[Query(arrayOf: Pet::class)]
    public function pets(): array
    {
        return [new Dog('Ana', 'Fido', 'Poodle')];
    }

    #[Query]
    public function firstNamed(): Named
    {
        return new Robot(7);
    }

    #[Query]
    public function ghost(): Named
    {
        return new Ghost();
    }

    /**
     * @return list<Dog|Robot>
     */
    #[Query(arrayOf: SearchResult::class)]
    public function search(string $term): array
    {
        return [new Robot(1), new Dog('Lee', $term, 'Husky')];
    }
}

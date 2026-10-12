<?php

declare(strict_types=1);

namespace Typic\Tests;

use PHPUnit\Framework\TestCase;
use Typic\Scanner\DirectoryScanner;
use Typic\Server\TypicServer;
use Typic\Tests\Fixtures\Animals\AnimalResolver;
use Typic\Tests\Fixtures\Animals\Dog;
use Typic\Tests\Fixtures\Animals\Ghost;
use Typic\Tests\Fixtures\Animals\Named;
use Typic\Tests\Fixtures\Animals\Pet;
use Typic\Tests\Fixtures\Animals\Robot;
use Typic\Tests\Fixtures\Animals\SearchResult;
use Typic\Typic;

class InterfaceUnionTest extends TestCase
{
    public function test_interface_fields_and_fragments(): void
    {
        $result = $this->server()->execute('{ named { __typename name ... on Dog { breed } ... on Robot { model } } }');

        $this->assertSame(['data' => ['named' => [
            ['__typename' => 'Dog', 'name' => 'Rex', 'breed' => 'Beagle'],
            ['__typename' => 'Robot', 'name' => 'RX-42', 'model' => 42],
        ]]], $result);
    }

    public function test_abstract_class_interface_inherits_its_parent_interface(): void
    {
        $result = $this->server()->execute('{ pets { owner name ... on Dog { breed } } firstNamed { name } }');

        $this->assertSame(['data' => [
            'pets' => [['owner' => 'Ana', 'name' => 'Fido', 'breed' => 'Poodle']],
            'firstNamed' => ['name' => 'RX-7'],
        ]], $result);
    }

    public function test_union(): void
    {
        $result = $this->server()->execute('{ search(term: "Balto") { __typename ... on Dog { name } ... on Robot { model } } }');

        $this->assertSame(['data' => ['search' => [
            ['__typename' => 'Robot', 'model' => 1],
            ['__typename' => 'Dog', 'name' => 'Balto'],
        ]]], $result);
    }

    public function test_introspection_shows_interfaces_and_possible_types(): void
    {
        $result = $this->server()->execute('{
            dog: __type(name: "Dog") { interfaces { name } fields { name } }
            pet: __type(name: "Pet") { kind interfaces { name } }
            named: __type(name: "Named") { kind description possibleTypes { name } }
            search: __type(name: "SearchResult") { kind description possibleTypes { name } }
        }');

        $this->assertArrayNotHasKey('errors', $result);
        $data = $result['data'];
        $this->assertEqualsCanonicalizing(['Named', 'Pet'], array_column($data['dog']['interfaces'], 'name'));
        $this->assertEqualsCanonicalizing(['owner', 'name', 'breed'], array_column($data['dog']['fields'], 'name'));
        $this->assertSame(['kind' => 'INTERFACE', 'interfaces' => [['name' => 'Named']]], $data['pet']);
        $this->assertSame('INTERFACE', $data['named']['kind']);
        $this->assertSame('Anything with a name', $data['named']['description']);
        $this->assertEqualsCanonicalizing(['Dog', 'Robot'], array_column($data['named']['possibleTypes'], 'name'));
        $this->assertSame('UNION', $data['search']['kind']);
        $this->assertSame('Something search can find', $data['search']['description']);
        $this->assertEqualsCanonicalizing(['Dog', 'Robot'], array_column($data['search']['possibleTypes'], 'name'));
    }

    public function test_returning_an_object_that_is_not_a_graphql_type_explains_the_fix(): void
    {
        $result = Typic::builder()
            ->registerResolvers([AnimalResolver::class])
            ->registerTypes([Dog::class, Robot::class])
            ->withDebug()
            ->build()
            ->execute('{ ghost { name } }');

        $this->assertStringContainsString(
            Ghost::class . ' is not a GraphQL type. Add #[ObjectType] to it and register or scan it.',
            $result['errors'][0]['extensions']['debugMessage'] ?? '',
        );
    }

    public function test_scanner_registers_interfaces_including_abstract_classes(): void
    {
        $scanned = (new DirectoryScanner())->scan([__DIR__ . '/Fixtures/Animals']);

        $this->assertEqualsCanonicalizing(
            [Named::class, Pet::class, Dog::class, Robot::class, SearchResult::class],
            $scanned['types'],
        );
    }

    private function server(): TypicServer
    {
        return Typic::builder()->scanDirectories([__DIR__ . '/Fixtures/Animals'])->build();
    }
}

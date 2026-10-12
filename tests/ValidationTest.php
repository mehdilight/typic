<?php

declare(strict_types=1);

namespace Typic\Tests;

use PHPUnit\Framework\TestCase;
use Typic\Server\TypicServer;
use Typic\Tests\ValidationFixtures\SignupResolver;
use Typic\Typic;

class ValidationTest extends TestCase
{
    private const SIGNUP = 'mutation ($input: SignupInput!) { signup(input: $input) }';

    protected function setUp(): void
    {
        SignupResolver::$calls = 0;
    }

    public function test_valid_input_reaches_the_resolver(): void
    {
        $result = $this->server()->execute(self::SIGNUP, ['input' => $this->validInput()]);

        $this->assertSame(['data' => ['signup' => 'welcome mehdi (free)']], $result);
    }

    public function test_renamed_input_fields_reach_the_php_object(): void
    {
        $result = $this->server()->execute(self::SIGNUP, ['input' => ['plan' => 'pro'] + $this->validInput()]);

        $this->assertSame(['data' => ['signup' => 'welcome mehdi (pro)']], $result);
    }

    public function test_every_problem_is_reported_by_path_before_the_resolver_runs(): void
    {
        $result = $this->server()->execute(self::SIGNUP, ['input' => [
            'username' => 'admin',
            'email' => 'not-an-email',
            'age' => 9,
            'plan' => 'gold',
            'website' => 'ftp://files.example',
            'address' => ['city' => '  ', 'country' => 'Morocco'],
            'tags' => ['a', 'b', 'c', 'd'],
            'previousAddresses' => [['city' => 'Rabat', 'country' => 'MA'], ['city' => 'Paris', 'country' => 'FRA']],
            'phone' => 'call me',
        ]]);

        $error = $result['errors'][0];
        $this->assertSame([
            'input.username' => ['is reserved'],
            'input.email' => ['must be a valid email address'],
            'input.age' => ['must be at least 13'],
            'input.plan' => ['must be one of: free, pro'],
            'input.website' => ['must be a valid URL'],
            'input.address.city' => ['must not be blank'],
            'input.address.country' => ['must be a 2-letter country code'],
            'input.tags' => ['must have at most 3 items'],
            'input.previousAddresses.1.country' => ['must be a 2-letter country code'],
            'input.phone' => ['must contain only digits and spaces'],
        ], $error['extensions']['validation']);
        $this->assertSame('Invalid input: input.username is reserved; input.email must be a valid email address; and 8 more.', $error['message']);
        $this->assertSame(0, SignupResolver::$calls);
    }

    public function test_one_field_can_break_several_rules(): void
    {
        $result = $this->server()->execute(self::SIGNUP, ['input' => ['username' => ''] + $this->validInput()]);

        $this->assertSame(['input.username' => ['must not be blank']], $result['errors'][0]['extensions']['validation']);

        $long = $this->server()->execute(self::SIGNUP, ['input' => ['username' => str_repeat('x', 21)] + $this->validInput()]);
        $this->assertSame(['input.username' => ['must be at most 20 characters']], $long['errors'][0]['extensions']['validation']);
    }

    public function test_rules_on_resolver_arguments_use_graphql_names(): void
    {
        $result = $this->server()->execute('{ search(term: "a", first: 99) }');

        $this->assertSame([
            'term' => ['must be at least 2 characters'],
            'first' => ['must be between 1 and 50'],
        ], $result['errors'][0]['extensions']['validation']);
        $this->assertSame(0, SignupResolver::$calls);

        $this->assertSame(['data' => ['search' => ['ok', 'ok']]], $this->server()->execute('{ search(term: "ok") }'));
    }

    public function test_dependent_implicit_and_each_rules_on_an_input_type(): void
    {
        $mutation = 'mutation ($input: AccountInput!) { openAccount(input: $input) }';
        $result = $this->server()->execute($mutation, ['input' => [
            'password' => 'short',
            'passwordConfirmation' => 'different',
            'notify' => 'EMAIL',
            'displayName' => '   ',
            'tags' => ['php', 'PHP', 'graphql', 'a b'],
            'trialStartsAt' => '2026-02-01T00:00:00+00:00',
            'trialEndsAt' => '2026-01-01T00:00:00+00:00',
        ]]);

        $this->assertSame([
            'input.password' => ['must be at least 8 characters'],
            'input.passwordConfirmation' => ['must match the password'],
            'input.email' => ['is required when notify is EMAIL'],
            'input.displayName' => ['must not be blank'],
            'input.tags' => ['must not contain duplicates'],
            'input.tags.2' => ['must be at most 5 characters'],
            'input.tags.3' => ['must contain only letters, numbers, dashes and underscores'],
            'input.trialEndsAt' => ['must be after trialStartsAt'],
        ], $result['errors'][0]['extensions']['validation']);

        $valid = $this->server()->execute($mutation, ['input' => [
            'password' => 'long enough',
            'passwordConfirmation' => 'long enough',
            'notify' => 'SMS',
            'phone' => '0600000000',
            'displayName' => 'Mehdi',
            'tags' => ['php', 'ws'],
        ]]);
        $this->assertSame(['data' => ['openAccount' => true]], $valid);
    }

    public function test_recursive_inputs_validate_every_nested_level(): void
    {
        $result = $this->server()->execute('mutation ($input: RecursiveInput!) { tree(input: $input) }', [
            'input' => ['name' => 'root', 'child' => ['name' => '', 'child' => ['name' => ' ']]],
        ]);
        $this->assertSame([
            'input.child.name' => ['must not be blank'],
            'input.child.child.name' => ['must not be blank'],
        ], $result['errors'][0]['extensions']['validation'] ?? null);
        $this->assertSame(0, SignupResolver::$calls);
    }

    /**
     * @return array<string, mixed>
     */
    private function validInput(): array
    {
        return ['username' => 'mehdi', 'email' => 'mehdi@example.com', 'age' => 30, 'website' => null];
    }

    private function server(): TypicServer
    {
        return Typic::builder()->registerResolvers([SignupResolver::class])->build();
    }
}

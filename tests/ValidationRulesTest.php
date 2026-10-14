<?php

declare(strict_types=1);

namespace Typic\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Typic\Validation as Assert;
use Typic\Validation\DependentRule;
use Typic\Validation\Rule;
use Typic\Validation\Violation;

class ValidationRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{Rule, mixed}>
     */
    public static function validValues(): iterable
    {
        yield 'not blank' => [new Assert\NotBlank(), 'x'];
        yield 'not blank list' => [new Assert\NotBlank(), [0]];
        yield 'length counts characters, not bytes' => [new Assert\Length(max: 3), 'éàü'];
        yield 'length of a list' => [new Assert\Length(min: 1, max: 2), ['a', 'b']];
        yield 'range' => [new Assert\Range(min: 1, max: 10), 10];
        yield 'range float' => [new Assert\Range(min: 0.5), 0.5];
        yield 'pattern' => [new Assert\Pattern('/^[a-z]+$/'), 'abc'];
        yield 'email' => [new Assert\Email(), 'a.b+tag@example.co.uk'];
        yield 'international email' => [new Assert\Email(), 'josé@example.com'];
        yield 'url' => [new Assert\Url(), 'https://example.com/path?q=1#top'];
        yield 'url with port and auth' => [new Assert\Url(), 'http://user:pass@localhost:8000/'];
        yield 'url international domain' => [new Assert\Url(), 'https://exämple.com'];
        yield 'url ipv4' => [new Assert\Url(), 'http://127.0.0.1:8080'];
        yield 'url ipv6' => [new Assert\Url(), 'http://[::1]:8080/'];
        yield 'url other scheme when allowed' => [new Assert\Url(schemes: ['ftp']), 'ftp://files.example.com'];
        yield 'one of' => [new Assert\OneOf(['a', 'b']), 'b'];
        yield 'one of, every list item' => [new Assert\OneOf(['a', 'b']), ['a', 'b', 'a']];
        yield 'not one of' => [new Assert\NotOneOf(['admin']), 'mehdi'];
        yield 'distinct' => [new Assert\Distinct(), ['a', 'A', 'b']];
        yield 'uuid' => [new Assert\Uuid(), '123E4567-e89b-12d3-a456-426614174000'];
        yield 'json' => [new Assert\Json(), '{"a": [1, 2]}'];
        yield 'json scalar' => [new Assert\Json(), 'null'];
        yield 'timezone' => [new Assert\Timezone(), 'Africa/Casablanca'];
        yield 'hex color' => [new Assert\HexColor(), '#1a2B3c'];
        yield 'hex color with alpha' => [new Assert\HexColor(), '#fff8'];
        yield 'alpha any language' => [new Assert\Alpha(), 'مهديÉlodie'];
        yield 'alpha num' => [new Assert\AlphaNum(), 'abc123'];
        yield 'alpha dash' => [new Assert\AlphaDash(), 'my_topic-2'];
        yield 'ip v4' => [new Assert\Ip(), '192.168.1.1'];
        yield 'ip v6 only' => [new Assert\Ip(6), '2001:db8::1'];
        yield 'starts with' => [new Assert\StartsWith('https://', 'http://'), 'http://x'];
        yield 'ends with' => [new Assert\EndsWith('.png', '.jpg'), 'cat.jpg'];
        yield 'multiple of int' => [new Assert\MultipleOf(5), 25];
        yield 'multiple of float' => [new Assert\MultipleOf(0.1), 0.3];
    }

    #[DataProvider('validValues')]
    public function test_valid_values_pass(Rule $rule, mixed $value): void
    {
        $this->assertNull($rule->check($value));
    }

    /**
     * @return iterable<string, array{Rule, mixed, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'blank string' => [new Assert\NotBlank(), "  \t", 'must not be blank'];
        yield 'empty list' => [new Assert\NotBlank(), [], 'must not be blank'];
        yield 'missing' => [new Assert\NotBlank(), null, 'must not be blank'];
        yield 'too short' => [new Assert\Length(min: 2), 'a', 'must be at least 2 characters'];
        yield 'too long' => [new Assert\Length(max: 2), 'abc', 'must be at most 2 characters'];
        yield 'exact length' => [new Assert\Length(min: 2, max: 2), 'abc', 'must be between 2 and 2 characters'];
        yield 'too many items' => [new Assert\Length(max: 1), [1, 2], 'must have at most 1 items'];
        yield 'below range' => [new Assert\Range(min: 1), 0, 'must be at least 1'];
        yield 'above range' => [new Assert\Range(max: 1.5), 2.0, 'must be at most 1.5'];
        yield 'pattern' => [new Assert\Pattern('/^[a-z]+$/'), 'ABC', 'has an invalid format'];
        yield 'pattern on a non-string' => [new Assert\Pattern('/^1$/'), [1], 'has an invalid format'];
        yield 'email' => [new Assert\Email(), 'nope@', 'must be a valid email address'];
        yield 'email header injection' => [new Assert\Email(), "a@example.com\r\nBcc: x@evil.com", 'must be a valid email address'];
        yield 'ascii-only email' => [new Assert\Email(unicode: false), 'josé@example.com', 'must be a valid email address'];
        yield 'url scheme' => [new Assert\Url(), 'ftp://files.example.com', 'must be a valid URL'];
        yield 'javascript url' => [new Assert\Url(), 'javascript:alert(1)', 'must be a valid URL'];
        yield 'url without scheme' => [new Assert\Url(), 'example.com', 'must be a valid URL'];
        yield 'url bad ipv4' => [new Assert\Url(), 'http://999.1.1.1/', 'must be a valid URL'];
        yield 'url bad ipv6' => [new Assert\Url(), 'http://[::zz]/', 'must be a valid URL'];
        yield 'url with spaces' => [new Assert\Url(), 'https://exa mple.com', 'must be a valid URL'];
        yield 'one of' => [new Assert\OneOf(['a', 'b']), 'c', 'must be one of: a, b'];
        yield 'one of is strict' => [new Assert\OneOf(['1']), 1, 'must be one of: 1'];
        yield 'one of, a list item' => [new Assert\OneOf(['a']), ['a', 'z'], 'must be one of: a'];
        yield 'one of, nested list' => [new Assert\OneOf(['a']), [['a']], 'must be one of: a'];
        yield 'not one of' => [new Assert\NotOneOf(['admin', 'root']), 'root', 'must not be one of: admin, root'];
        yield 'duplicates' => [new Assert\Distinct(), ['a', 'b', 'a'], 'must not contain duplicates'];
        yield 'duplicates ignoring case' => [new Assert\Distinct(ignoreCase: true), ['Ana', 'ana'], 'must not contain duplicates'];
        yield 'uuid' => [new Assert\Uuid(), '123e4567-e89b-12d3-a456-42661417400', 'must be a valid UUID'];
        yield 'uuid with trailing newline' => [new Assert\Uuid(), "123e4567-e89b-12d3-a456-426614174000\n", 'must be a valid UUID'];
        yield 'json' => [new Assert\Json(), '{a: 1}', 'must be valid JSON'];
        yield 'timezone' => [new Assert\Timezone(), 'Mars/Olympus', 'must be a valid time zone'];
        yield 'hex color' => [new Assert\HexColor(), '#12345', 'must be a hex color'];
        yield 'alpha' => [new Assert\Alpha(), 'abc1', 'must contain only letters'];
        yield 'alpha ascii' => [new Assert\Alpha(ascii: true), 'é', 'must contain only letters'];
        yield 'alpha dash' => [new Assert\AlphaDash(), 'a b', 'must contain only letters, numbers, dashes and underscores'];
        yield 'ip' => [new Assert\Ip(), '256.1.1.1', 'must be a valid IP address'];
        yield 'ip v4 only' => [new Assert\Ip(4), '::1', 'must be a valid IPv4 address'];
        yield 'starts with' => [new Assert\StartsWith('https://'), 'http://x', 'must start with one of: https://'];
        yield 'ends with' => [new Assert\EndsWith('.png'), 'cat.gif', 'must end with one of: .png'];
        yield 'multiple of' => [new Assert\MultipleOf(5), 12, 'must be a multiple of 5'];
        yield 'multiple of float' => [new Assert\MultipleOf(0.25), 0.3, 'must be a multiple of 0.25'];
        yield 'custom message' => [new Assert\Length(max: 1, message: 'too long!'), 'ab', 'too long!'];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_explain_what_is_wrong(Rule $rule, mixed $value, string $message): void
    {
        $violation = $rule->check($value);
        $this->assertInstanceOf(Violation::class, $violation);
        $this->assertSame($message, $violation->message);
    }

    /**
     * @return iterable<string, array{DependentRule, mixed, array<string, mixed>, string|null}>
     */
    public static function dependentRules(): iterable
    {
        yield 'same' => [new Assert\Same('password'), 'secret', ['password' => 'secret'], null];
        yield 'not same' => [new Assert\Same('password'), 'oops', ['password' => 'secret'], 'must match password'];
        yield 'different' => [new Assert\Different('oldPassword'), 'new', ['oldPassword' => 'old'], null];
        yield 'not different' => [new Assert\Different('oldPassword'), 'old', ['oldPassword' => 'old'], 'must be different from oldPassword'];
        yield 'required if, condition met' => [new Assert\RequiredIf('contact', 'EMAIL'), null, ['contact' => 'EMAIL'], 'is required when contact is EMAIL'];
        yield 'required if, given' => [new Assert\RequiredIf('contact', ['EMAIL', 'BOTH']), 'a@b.c', ['contact' => 'BOTH'], null];
        yield 'required if, condition not met' => [new Assert\RequiredIf('contact', 'EMAIL'), null, ['contact' => 'PHONE'], null];
        yield 'required unless' => [new Assert\RequiredUnless('plan', 'free'), '', ['plan' => 'pro'], 'is required unless plan is free'];
        yield 'required unless, exempt' => [new Assert\RequiredUnless('plan', 'free'), null, ['plan' => 'free'], null];
        yield 'prohibited if' => [new Assert\ProhibitedIf('plan', 'free'), 'XYZ', ['plan' => 'free'], 'must be left out when plan is free'];
        yield 'prohibited if, allowed' => [new Assert\ProhibitedIf('plan', 'free'), 'XYZ', ['plan' => 'pro'], null];
        yield 'required with' => [new Assert\RequiredWith('city', 'zip'), null, ['zip' => '20000'], 'is required when city or zip is given'];
        yield 'required with, none given' => [new Assert\RequiredWith('city'), null, ['city' => ''], null];
        yield 'required without' => [new Assert\RequiredWithout('phone'), null, [], 'is required when phone is missing'];
        yield 'required without, other given' => [new Assert\RequiredWithout('phone'), null, ['phone' => '0600'], null];
        yield 'after field' => [new Assert\After('startsAt'), new DateTimeImmutable('2026-01-02'), ['startsAt' => new DateTimeImmutable('2026-01-01')], null];
        yield 'not after field' => [new Assert\After('startsAt'), new DateTimeImmutable('2026-01-01'), ['startsAt' => new DateTimeImmutable('2026-01-01')], 'must be after startsAt'];
        yield 'after or equal' => [new Assert\AfterOrEqual('startsAt'), new DateTimeImmutable('2026-01-01'), ['startsAt' => new DateTimeImmutable('2026-01-01')], null];
        yield 'before a date' => [new Assert\Before('2000-01-01'), new DateTimeImmutable('1999-12-31'), [], null];
        yield 'not before now' => [new Assert\Before('now'), new DateTimeImmutable('+1 day'), [], 'must be before now'];
        yield 'before or equal' => [new Assert\BeforeOrEqual('endsAt'), new DateTimeImmutable('2026-03-01'), ['endsAt' => new DateTimeImmutable('2026-02-01')], 'must be before or equal to endsAt'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('dependentRules')]
    public function test_dependent_rules(DependentRule $rule, mixed $value, array $input, ?string $message): void
    {
        $this->assertSame($message, $rule->check($value, $input)?->message);
    }

    public function test_a_broken_pattern_fails_immediately(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid regular expression /(unclosed/');

        new Assert\Pattern('/(unclosed/');
    }

    /**
     * @return iterable<string, array{Rule, mixed, string, array<string, mixed>}>
     */
    public static function codedViolations(): iterable
    {
        yield 'too short' => [new Assert\Length(min: 3, max: 10), 'ab', 'TOO_SHORT', ['min' => 3, 'max' => 10, 'unit' => 'characters']];
        yield 'too long' => [new Assert\Length(min: 3, max: 10), str_repeat('a', 11), 'TOO_LONG', ['min' => 3, 'max' => 10, 'unit' => 'characters']];
        yield 'too small' => [new Assert\Range(min: 1), 0, 'TOO_SMALL', ['min' => 1, 'max' => null]];
        yield 'too large' => [new Assert\Range(max: 5), 6, 'TOO_LARGE', ['min' => null, 'max' => 5]];
        yield 'email' => [new Assert\Email(), 'nope', 'INVALID_EMAIL', []];
        yield 'pattern' => [new Assert\Pattern('/^a$/'), 'b', 'INVALID_FORMAT', []];
        yield 'blank' => [new Assert\NotBlank(), ' ', 'BLANK', []];
        yield 'choice' => [new Assert\OneOf(['a', 'b']), 'c', 'INVALID_CHOICE', ['values' => ['a', 'b']]];
        yield 'custom message keeps the code' => [new Assert\Length(max: 1, message: 'too long!'), 'ab', 'TOO_LONG', ['min' => null, 'max' => 1, 'unit' => 'characters']];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('codedViolations')]
    public function test_violations_carry_a_stable_code_and_params(Rule $rule, mixed $value, string $code, array $params): void
    {
        $violation = $rule->check($value);

        $this->assertInstanceOf(Violation::class, $violation);
        $this->assertSame([$code, $params], [$violation->code, $violation->params]);
    }
}

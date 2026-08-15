<?php

declare(strict_types=1);

use Simtabi\Laranail\Email\Email;

it('splits on the last at-sign, not the first', function (): void {
    // "a@b"@example.com is a legal address whose domain is example.com.
    // Splitting on the first @ gets BOTH halves wrong.
    $email = Email::parse('"a@b"@example.com');

    expect($email?->localPart)->toBe('"a@b"')
        ->and($email?->domain)->toBe('example.com');
});

it('lowercases the domain but not the local part', function (): void {
    // Domains are case-insensitive by DNS. Local parts are case-SENSITIVE per
    // RFC 5321 §2.4 — most providers ignore case, but that is their policy,
    // and a library that lowercases silently decides on their behalf.
    $email = Email::parse('Alice@Example.COM');

    expect($email?->localPart)->toBe('Alice')
        ->and($email?->domain)->toBe('example.com');
});

it('separates the mailbox from its plus tag', function (): void {
    $email = Email::parse('alice+newsletter@example.com');

    expect($email?->mailbox())->toBe('alice')
        ->and($email?->tag())->toBe('newsletter');
});

it('reports no tag when there is none, and none for a bare plus', function (): void {
    expect(Email::parse('alice@example.com')?->tag())->toBeNull()
        ->and(Email::parse('alice+@example.com')?->tag())->toBeNull()
        ->and(Email::parse('alice+@example.com')?->mailbox())->toBe('alice');
});

it('canonicalises to one form per mailbox', function (): void {
    expect(Email::parse('Alice+Promo@Example.com')?->canonical())->toBe('alice@example.com');
});

it('treats tagged and cased variants as the same mailbox', function (): void {
    $email = Email::parse('alice@example.com');

    expect($email?->equals('Alice+tag@EXAMPLE.com'))->toBeTrue()
        ->and($email?->equals('bob@example.com'))->toBeFalse()
        ->and($email?->equals('nonsense'))->toBeFalse();
});

it('matches a domain and its subdomains, but not a lookalike', function (): void {
    $email = Email::parse('alice@mail.example.com');

    expect($email?->isAt('example.com'))->toBeTrue()
        ->and($email?->isAt('mail.example.com'))->toBeTrue()
        // The hole in a naive str_ends_with check.
        ->and($email?->isAt('ample.com'))->toBeFalse()
        ->and($email?->isAt('evilexample.com'))->toBeFalse();
});

it('returns null for a string with no plausible shape', function (string $value): void {
    expect(Email::parse($value))->toBeNull();
})->with(['', 'no-at-sign', '@example.com', 'alice@', '   ', 'alice@@']);

it('round-trips through its string form', function (): void {
    expect((string) Email::parse('Alice@Example.com'))->toBe('Alice@example.com');
});

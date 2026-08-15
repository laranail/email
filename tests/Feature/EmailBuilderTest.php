<?php

declare(strict_types=1);

use Simtabi\Laranail\Email\Facades\Mail;

// =========================================================================
// Parsing through a chain
// =========================================================================

it('parses an address through a chain', function (): void {
    $email = Mail::of('Alice+Newsletter@Example.COM');

    expect($email->localPart())->toBe('Alice+Newsletter')
        // Domains are case-insensitive by DNS, so lowercasing one is safe.
        ->and($email->domain())->toBe('example.com')
        // Case preserved: RFC 5321 §2.4 makes local parts case-sensitive, so only canonical()
        // lowercases — and it does so as a stated policy rather than silently while parsing.
        ->and($email->mailbox())->toBe('Alice')
        ->and($email->tag())->toBe('Newsletter')
        ->and($email->canonical())->toBe('alice@example.com');
});

it('hands back null rather than throwing for something that is not an address', function (): void {
    expect(Mail::of('not an address')->isParseable())->toBeFalse()
        ->and(Mail::of('not an address')->canonical())->toBeNull()
        ->and(Mail::of(null)->isParseable())->toBeFalse();
});

it('never mutates the builder it was called on', function (): void {
    $base = Mail::of('alice+news@example.com');
    $tagged = $base->keepSubaddress();

    expect($tagged->canonical())->toBe('alice+news@example.com')
        ->and($base->canonical())->toBe('alice@example.com');
});

it('parses once however long the chain', function (): void {
    $email = Mail::of('alice@example.com');

    expect($email->value())->toBe($email->value());
});

// =========================================================================
// Canonicalisation, which is a policy rather than a standard
// =========================================================================

/**
 * RFC 5321 §2.4 makes local parts case-*sensitive*. Most providers choose to ignore case, but that
 * is their policy — so canonicalising is a separate, named decision rather than something parsing
 * does silently.
 */
it('treats tagged and untagged addresses as one mailbox by default', function (): void {
    expect(Mail::of('alice+news@example.com')->equals('alice@example.com'))->toBeTrue()
        ->and(Mail::of('ALICE@EXAMPLE.COM')->equals('alice@example.com'))->toBeTrue()
        ->and(Mail::of('alice@example.com')->equals('bob@example.com'))->toBeFalse();
});

it('keeps them distinct when the tag is meaningful', function (): void {
    expect(Mail::of('alice+news@example.com')->keepSubaddress()->equals('alice@example.com'))->toBeFalse();
});

/**
 * The operation an import actually needs. A naive `array_unique` keeps all three of these, and the
 * result is one person in the list three times.
 */
it('deduplicates a list down to one entry per mailbox', function (): void {
    $unique = Mail::unique([
        'alice@example.com',
        'Alice@Example.com',
        'alice+news@example.com',
        'bob@example.com',
        'not an address',
        null,
    ]);

    expect($unique)->toBe(['alice@example.com', 'bob@example.com']);
});

// =========================================================================
// Judgements
// =========================================================================

it('reports every problem at once rather than one per submission', function (): void {
    // A form that surfaces one failure per round trip makes the user discover them one at a time.
    expect(Mail::of('not an address')->problems())->toBe(['unparseable'])
        ->and(Mail::of('alice@example.com')->problems())->toBe([])
        ->and(Mail::of('alice@example.com')->isUsable())->toBeTrue();
});

it('does not touch the network unless asked', function (): void {
    // The MX lookup is the only check here that leaves the process, so a caller iterating ten
    // thousand addresses has to opt into it deliberately.
    expect(Mail::of('alice@example.com')->problems())->not->toContain('unreachable');
});

it('exposes the address as an array for logging and APIs', function (): void {
    $array = Mail::of('Alice+News@Example.COM')->toArray();

    expect($array['domain'])->toBe('example.com')
        ->and($array['mailbox'])->toBe('Alice')
        ->and($array['tag'])->toBe('News')
        ->and($array['canonical'])->toBe('alice@example.com')
        ->and($array)->toHaveKeys(['disposable', 'role_account']);
});

it('reads the same way as the phone builder', function (): void {
    // The sibling packages solve the same shape of problem, and a developer who has learned one
    // should not have to learn the other. Both expose of(), value(), toArray() and __toString().
    expect(method_exists(Mail::of('a@b.com'), 'value'))->toBeTrue()
        ->and((string) Mail::of('alice@example.com'))->toBe('alice@example.com');
});

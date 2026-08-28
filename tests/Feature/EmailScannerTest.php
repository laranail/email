<?php

declare(strict_types=1);

use Simtabi\Laranail\Email\EmailScanner;
use Simtabi\Laranail\Email\Enums\ScanLeniency;
use Simtabi\Laranail\Email\Support\EmailMatch;
use Simtabi\Laranail\Email\Testing\FakeDnsResolver;
use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;

/*
|--------------------------------------------------------------------------
| Scanning free text
|--------------------------------------------------------------------------
|
| Two kinds of test here. The offsets, because a caller that cannot map a
| match back to its position redacts the wrong occurrence. And the boundary
| cases — trailing punctuation, angle brackets, single-label hosts — because
| that is where a naive pattern is wrong in a way nobody notices until a
| redacted document leaks.
|
*/

function scannerWith(DnsResolver $dns, ScanLeniency $leniency = ScanLeniency::Valid): EmailScanner
{
    return new EmailScanner(dns: $dns, leniency: $leniency);
}

it('finds addresses in prose, in order, with their offsets', function (): void {
    $text = 'Write to alice@example.com or bob@example.org before Friday.';

    $matches = EmailAddress::find($text);

    expect($matches)->toHaveCount(2)
        ->and($matches[0]->raw)->toBe('alice@example.com')
        ->and($matches[0]->offset)->toBe(9)
        ->and(substr($text, $matches[0]->offset, strlen($matches[0]->raw)))->toBe('alice@example.com')
        ->and($matches[1]->raw)->toBe('bob@example.org');
});

it('leaves sentence punctuation out of the address', function (): void {
    // A domain may legally end in a dot — the DNS root — so the pattern cannot tell that apart from
    // a full stop. A person writing prose never means the root.
    expect(EmailAddress::find('Email me at alice@example.com.')[0]->raw)->toBe('alice@example.com')
        ->and(EmailAddress::find('(alice@example.com)')[0]->raw)->toBe('alice@example.com')
        ->and(EmailAddress::find('alice@example.com, bob@example.org')[0]->raw)->toBe('alice@example.com')
        ->and(EmailAddress::find('Contact: <alice@example.com>')[0]->raw)->toBe('alice@example.com');
});

it('does not start a match in the middle of a word', function (): void {
    // Without the lookbehind this reports `alice@example.com` at an offset that highlights the wrong
    // span, and a redaction leaves the `x-` prefix behind.
    $matches = EmailAddress::find('id x-alice@example.com here');

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->raw)->toBe('x-alice@example.com');
});

it('keeps a plus tag, because it is part of the address', function (): void {
    $match = EmailAddress::find('reply to alice+support@example.com')[0];

    expect($match->raw)->toBe('alice+support@example.com')
        ->and($match->email->tag())->toBe('support')
        ->and($match->email->canonical())->toBe('alice@example.com');
});

it('ignores a single-label host at the default leniency', function (): void {
    // `ssh deploy@web-01` is a command, not a contact. This is the whole reason VALID is the default.
    expect(EmailAddress::find('run ssh deploy@web-01 to check'))->toBe([])
        ->and(EmailAddress::find('root@localhost is fine'))->toBe([]);
});

it('finds a single-label host when asked to', function (): void {
    // Real addresses on a private network, and the right answer when redacting a log.
    $matches = EmailAddress::find('run ssh deploy@web-01 to check', ScanLeniency::Possible);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->raw)->toBe('deploy@web-01');
});

it('ignores things shaped like an address that never are one', function (): void {
    expect(EmailAddress::find('bump to service.v2 in config'))->toBe([])
        ->and(EmailAddress::find('see docs at example.com'))->toBe([])
        ->and(EmailAddress::find('mention @example in the thread'))->toBe([]);
});

it('accepts a long or internationalised top-level label', function (): void {
    expect(EmailAddress::find('press@bbc.co.uk')[0]->email->domain)->toBe('bbc.co.uk')
        ->and(EmailAddress::find('hello@example.technology')[0]->email->domain)->toBe('example.technology')
        ->and(EmailAddress::find('hi@example.xn--p1ai')[0]->email->domain)->toBe('example.xn--p1ai');
});

it('checks deliverability only when asked, and once per domain', function (): void {
    // Four addresses, two domains, two lookups — a page of addresses is overwhelmingly a handful of
    // providers, so this is the ordinary shape rather than a corner case.
    $dns = FakeDnsResolver::deliverable('example.com');

    $matches = scannerWith($dns, ScanLeniency::Deliverable)->scan(
        'a@example.com b@example.com c@nowhere.test d@nowhere.test',
    );

    expect($dns->asked())->toBe(['example.com', 'nowhere.test'])
        ->and(array_map(static fn (EmailMatch $m): string => $m->raw, $matches))
        ->toBe(['a@example.com', 'b@example.com']);
});

it('makes no DNS query below the deliverable rung', function (): void {
    $dns = FakeDnsResolver::everything();

    scannerWith($dns)->scan('a@example.com b@example.org');

    expect($dns->asked())->toBe([]);
});

it('replaces from the end, so earlier offsets stay valid', function (): void {
    // The bug every hand-rolled version of this has, and it only shows on the second address.
    $replaced = EmailAddress::replaceIn(
        'from alice@example.com to bob@example.org',
        static fn (EmailMatch $m): string => '<' . $m->email->domain . '>',
    );

    expect($replaced)->toBe('from <example.com> to <example.org>');
});

it('redacts the mailbox and keeps the domain', function (): void {
    // Knowing a complaint came from a gov.uk address, or that forty came from one provider, is often
    // the reason the log is being read at all.
    expect(EmailAddress::redact('contact alice@example.com now'))
        ->toBe('contact a••••@example.com now');
});

it('redacts at the widest leniency by default', function (): void {
    // When redacting, a false positive costs a blacked-out word and a false negative leaks a real
    // address — so the asymmetry runs the other way from extraction, and the default follows it.
    expect(EmailAddress::redact('deploy@web-01 failed'))->toBe('d•••••@web-01 failed');
});

it('replaces the right occurrence when an address repeats', function (): void {
    $text = 'alice@example.com wrote; reply to alice@example.com';

    $replaced = EmailAddress::replaceIn($text, static fn (EmailMatch $m): string => strtoupper($m->raw));

    expect($replaced)->toBe('ALICE@EXAMPLE.COM wrote; reply to ALICE@EXAMPLE.COM');
});

it('handles nothing, gracefully', function (): void {
    expect(EmailAddress::find(null))->toBe([])
        ->and(EmailAddress::find(''))->toBe([])
        ->and(EmailAddress::find('   '))->toBe([])
        ->and(EmailAddress::redact(null))->toBeNull()
        ->and(EmailAddress::redact('nothing here'))->toBe('nothing here');
});

it('honours a match ceiling', function (): void {
    $scanner = new EmailScanner(dns: FakeDnsResolver::everything(), limit: 2);

    expect($scanner->scan('a@example.com b@example.com c@example.com d@example.com'))->toHaveCount(2);
});

it('serialises a match with everything a client needs to act on it', function (): void {
    $json = EmailAddress::find('write to Alice+News@Example.COM')[0]->toArray();

    expect($json)->toMatchArray([
        'raw'       => 'Alice+News@Example.COM',
        'offset'    => 9,
        'address'   => 'Alice+News@example.com',
        'canonical' => 'alice@example.com',
        'domain'    => 'example.com',
    ])->and($json['end'])->toBe(9 + strlen('Alice+News@Example.COM'));
});

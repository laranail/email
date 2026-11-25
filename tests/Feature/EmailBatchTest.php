<?php

declare(strict_types=1);

use Simtabi\Laranail\Email\EmailBatch;
use Simtabi\Laranail\Email\Support\EmailAuditEntry;
use Simtabi\Laranail\Email\Testing\FakeDnsResolver;
use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\RoleAccountList;
use Simtabi\Laranail\Validation\Contracts\Email\DisposableDomainList;

/*
|--------------------------------------------------------------------------
| Batch and audit
|--------------------------------------------------------------------------
|
| Two questions, one pass: what is each of these, and what is wrong with the
| list. The test that matters most here is the per-domain MX grouping — it is
| the difference between batch reachability being a feature and being a way
| to make ten thousand DNS queries.
|
*/

function batchWith(DnsResolver $dns): EmailBatch
{
    return new EmailBatch(
        disposable: app(DisposableDomainList::class),
        roleAccounts: app(RoleAccountList::class),
        dns: $dns,
    );
}

it('answers per-row and in aggregate from one pass', function (): void {
    $audit = EmailAddress::audit([
        'alice@example.com',
        'Alice@Example.com',        // the same mailbox, differently cased
        'alice+news@example.com',   // and again, subaddressed
        'support@example.com',      // a role account
        'not an address',
    ]);

    expect($audit)->toHaveCount(5)
        ->and($audit->summary())->toMatchArray([
            'total'       => 5,
            'unparseable' => 1,
            'duplicates'  => 2,
            'distinct'    => 3,
        ])
        ->and($audit->unique())->toBe(['alice@example.com', 'support@example.com'])
        ->and($audit->problems())->toMatchArray(['role_account' => 1, 'unparseable' => 1]);
});

it('treats every spelling of one mailbox as one row', function (): void {
    // The whole reason to run this over a signup table: four rows, one inbox, and a unique index on
    // the raw column allows all four.
    $audit = EmailAddress::audit([
        'alice@example.com',
        'Alice@Example.com',
        'alice+newsletter@example.com',
        'bob@example.com',
    ]);

    expect($audit->duplicateGroups())->toBe(['alice@example.com' => [0, 1, 2]])
        ->and(array_map(static fn (EmailAuditEntry $e): int => $e->index, $audit->distinct()))->toBe([0, 3]);
});

it('keeps subaddressed mailboxes apart when asked', function (): void {
    // Subaddressing is a real feature and some products route on the tag.
    $audit = EmailAddress::audit([
        'alice@example.com',
        'alice+news@example.com',
    ], keepSubaddress: true);

    expect($audit->duplicateGroups())->toBe([])
        ->and($audit->summary()['distinct'])->toBe(2);
});

it('points a duplicate at the first row that produced it, not the last', function (): void {
    $audit = EmailAddress::audit(['a@example.com', 'b@example.com', 'A@example.com']);

    expect($audit->duplicates()[0]->index)->toBe(2)
        ->and($audit->duplicates()[0]->duplicateOf)->toBe(0);
});

it('resolves reachability once per domain, not once per address', function (): void {
    // This is the single reason batch reachability is usable at all. Six addresses, two domains, two
    // lookups. Per address it is six, and at ten thousand rows it is a denial of service you wrote
    // yourself.
    $dns = FakeDnsResolver::deliverable('example.com');

    $audit = batchWith($dns)->audit([
        'a@example.com', 'b@example.com', 'c@example.com',
        'd@nowhere.test', 'e@nowhere.test', 'f@nowhere.test',
    ], checkReachability: true);

    expect($dns->asked())->toBe(['example.com', 'nowhere.test'])
        ->and($audit->problems())->toBe(['unreachable' => 3])
        ->and($audit->summary())->toMatchArray(['usable' => 3, 'unusable' => 3, 'checked_reachability' => true]);
});

it('makes no DNS query at all unless reachability was asked for', function (): void {
    // The only thing in this package that leaves the process, so it stays opt-in rather than merely
    // documented.
    $dns = FakeDnsResolver::everything();

    $audit = batchWith($dns)->audit(['a@example.com', 'b@example.com']);

    expect($dns->asked())->toBe([])
        ->and($audit->entries()[0]->reachable)->toBeNull()
        ->and($audit->summary()['checked_reachability'])->toBeFalse();
});

it('reports a skipped reachability check as unknown rather than as a failure', function (): void {
    // Null, not false. A false would read as "we looked and there is no mail exchanger", which is a
    // different and much stronger claim than "we did not look".
    $entry = EmailAddress::audit(['a@example.com'])->entries()[0];

    expect($entry->reachable)->toBeNull()
        ->and($entry->hasProblem('unreachable'))->toBeFalse();
});

it('counts a domain breakdown, commonest first', function (): void {
    // One domain holding most of a list is either a corporate export or a leak, and the two need
    // different handling.
    $audit = EmailAddress::audit([
        'a@big.example', 'b@big.example', 'c@big.example', 'd@small.example',
    ]);

    expect($audit->domains())->toBe(['big.example' => 3, 'small.example' => 1]);
});

it('lists every problem a row has, not the first one', function (): void {
    config()->set('laranail.email.role_accounts', ['billing']);
    app()->forgetInstance(RoleAccountList::class);

    $dns = FakeDnsResolver::nothing();

    $audit = batchWith($dns)->audit(['billing@nowhere.test'], checkReachability: true);

    expect($audit->entries()[0]->problems)->toBe(['role_account', 'unreachable']);
});

it('streams without accumulating, and still detects duplicates', function (): void {
    $seen = [];

    foreach (EmailAddress::each(['alice@example.com', 'ALICE@example.com', 'bob@example.com']) as $entry) {
        $seen[] = [$entry->index, $entry->duplicateOf];
    }

    expect($seen)->toBe([[0, null], [1, 0], [2, null]]);
});

it('turns a column of whatever people typed into the mailboxes it contains', function (): void {
    expect(EmailAddress::unique([
        'alice@example.com',
        'Alice@Example.com',
        'alice+news@example.com',
        'bob@example.com',
        null,
        '   ',
        'junk',
    ]))->toBe(['alice@example.com', 'bob@example.com']);
});

it('counts nothing as nothing', function (): void {
    $audit = EmailAddress::audit([]);

    expect($audit->isEmpty())->toBeTrue()
        ->and($audit->summary())->toMatchArray(['total' => 0, 'usable' => 0, 'unusable' => 0]);
});

it('reconciles: usable plus unusable is always the total', function (): void {
    $audit = EmailAddress::audit(['a@example.com', 'junk', null, '', 'support@example.com']);

    $summary = $audit->summary();

    expect($summary['usable'] + $summary['unusable'])->toBe($summary['total'])
        ->and($summary['distinct'] + $summary['duplicates'])->toBe($summary['total']);
});

it('serialises the whole verdict, entries and all', function (): void {
    $json = json_decode(json_encode(EmailAddress::audit(['Alice+News@Example.COM', 'junk']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($json)->toHaveKeys(['summary', 'domains', 'problems', 'duplicates', 'entries'])
        ->and($json['entries'][0])->toMatchArray([
            'index'        => 0,
            'input'        => 'Alice+News@Example.COM',
            'canonical'    => 'alice@example.com',
            'mailbox'      => 'Alice',
            'tag'          => 'News',
            'duplicate_of' => null,
        ]);
});

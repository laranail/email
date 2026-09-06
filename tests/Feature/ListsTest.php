<?php

declare(strict_types=1);

use Simtabi\Laranail\Email\Lists\MaintainedRoleAccountList;
use Simtabi\Laranail\Email\Lists\MaintainedDisposableDomainList;
use Simtabi\Laranail\Validation\Support\Email\BundledRoleAccountList;

it('recognises a listed disposable domain', function (): void {
    $list = new MaintainedDisposableDomainList;

    expect($list->contains('mailinator.com'))->toBeTrue()
        ->and($list->contains('example.com'))->toBeFalse();
});

it('catches a subdomain of a listed domain', function (): void {
    // Providers hand out subdomains freely; listing every one is not possible,
    // so matching walks up the labels.
    $list = new MaintainedDisposableDomainList;

    expect($list->contains('mail.mailinator.com'))->toBeTrue()
        ->and($list->contains('a.b.mailinator.com'))->toBeTrue();
});

it('does not match a lookalike parent', function (): void {
    $list = new MaintainedDisposableDomainList;

    // Walking up must not turn "com" into a match for everything, and a
    // domain that merely ENDS WITH a listed one is not a subdomain of it.
    // (notmailinator.com is deliberately not used here: it is genuinely on
    // the CC0 list, which is what makes it a bad test of the boundary.)
    expect($list->contains('definitely-not-mailinator.com'))->toBeFalse()
        ->and($list->contains('com'))->toBeFalse()
        ->and($list->contains('mailinator.example'))->toBeFalse();
});

it('prefers a refreshed list over the bundled snapshot', function (): void {
    $refreshed = sys_get_temp_dir() . '/laranail-email-refreshed.txt';
    file_put_contents($refreshed, "# test\nonly-this-one.test\n");

    $list = new MaintainedDisposableDomainList(refreshedPath: $refreshed);

    expect($list->contains('only-this-one.test'))->toBeTrue()
        // mailinator is in the bundled file but NOT the refreshed one, and the
        // refreshed one wins outright rather than merging.
        ->and($list->contains('mailinator.com'))->toBeFalse();

    @unlink($refreshed);
});

it('falls back to the snapshot when the refreshed file is empty', function (): void {
    // An empty refreshed file means a truncated download, not a world with no
    // disposable domains. A list that quietly becomes empty is worse than no
    // list, because the application still believes it is filtering.
    $refreshed = sys_get_temp_dir() . '/laranail-email-empty.txt';
    file_put_contents($refreshed, "# nothing but comments\n");

    $list = new MaintainedDisposableDomainList(refreshedPath: $refreshed);

    expect($list->contains('mailinator.com'))->toBeTrue();

    @unlink($refreshed);
});

it('knows the RFC 2142 mailboxes', function (string $localPart): void {
    expect((new MaintainedRoleAccountList)->contains($localPart))->toBeTrue();
})->with(['postmaster', 'abuse', 'hostmaster', 'webmaster', 'info', 'sales', 'support', 'security', 'noc']);

it('does not treat an ordinary name as a role account', function (): void {
    expect((new MaintainedRoleAccountList)->contains('alice'))->toBeFalse();
});

it('takes extra role accounts from config', function (): void {
    // "Which addresses are not a person" has a house style.
    config()->set('laranail.email.role_accounts', ['bookings']);

    expect((new MaintainedRoleAccountList)->contains('bookings'))->toBeTrue()
        ->and((new MaintainedRoleAccountList)->contains('BOOKINGS'))->toBeTrue();
});

it('carries more entries than the bundled fallback it replaces', function (): void {
    // The reason to install this package at all.
    $bundled = new BundledRoleAccountList;

    expect((new MaintainedRoleAccountList)->count())->toBeGreaterThan(count($bundled->terms ?? []) ?: 40);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Email\Resolvers\CachedDnsResolver;
use Simtabi\Laranail\Email\Testing\FakeDnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;
use Simtabi\Laranail\Validation\Rules\Network\DeliverableEmail;

it('short-circuits the providers most addresses belong to', function (): void {
    // No lookup, no cache entry — these will not stop accepting mail while a
    // request is in flight, and skipping them removes most of the traffic.
    Cache::flush();

    expect((new CachedDnsResolver)->hasMailExchanger('gmail.com'))->toBeTrue()
        ->and(Cache::has('laranail-email:mx:gmail.com'))->toBeFalse();
});

it('caches a negative for far less time than a positive', function (): void {
    // A domain that resolves today will resolve tomorrow. A domain that failed
    // may only have hit a transient outage, and caching that for a day turns a
    // blip into a day of rejected signups.
    $positive = config('laranail.email.dns.positive_ttl');
    $negative = config('laranail.email.dns.negative_ttl');

    expect($positive)->toBeInt()
        ->and($negative)->toBeInt()
        ->and($positive)->toBeGreaterThan($negative);
});

it('answers from the cache without looking up again', function (): void {
    Cache::flush();
    Cache::put('laranail-email:mx:cached.test', true, 60);

    expect((new CachedDnsResolver)->hasMailExchanger('cached.test'))->toBeTrue();
});

it('rejects an empty domain without touching the resolver', function (): void {
    expect((new CachedDnsResolver)->hasMailExchanger('   '))->toBeFalse();
});

// =========================================================================
// The fake — shipped so tests never make a DNS query
// =========================================================================

it('answers from its list and records what it was asked', function (): void {
    $dns = FakeDnsResolver::deliverable('example.com');

    expect($dns->hasMailExchanger('example.com'))->toBeTrue()
        ->and($dns->hasMailExchanger('other.test'))->toBeFalse()
        ->and($dns->asked())->toBe(['example.com', 'other.test'])
        ->and($dns->wasAsked('example.com'))->toBeTrue()
        ->and($dns->wasAsked('never.test'))->toBeFalse();
});

it('offers a everything-resolves and a nothing-resolves fake', function (): void {
    expect(FakeDnsResolver::everything()->hasMailExchanger('anything.test'))->toBeTrue()
        ->and(FakeDnsResolver::nothing()->hasMailExchanger('anything.test'))->toBeFalse();
});

it('stands in for the real resolver through the container', function (): void {
    $dns = FakeDnsResolver::deliverable('example.com');
    app()->instance(DnsResolver::class, $dns);

    $rule = new DeliverableEmail;

    expect(validator(['e' => 'a@example.com'], ['e' => [$rule]])->passes())->toBeTrue()
        ->and(validator(['e' => 'a@nowhere.test'], ['e' => [$rule]])->passes())->toBeFalse()
        ->and($dns->wasAsked('example.com'))->toBeTrue();
});

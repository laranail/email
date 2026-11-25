<?php

declare(strict_types=1);

use Simtabi\Laranail\Email\Lists\MaintainedDisposableDomainList;
use Simtabi\Laranail\Email\Lists\MaintainedRoleAccountList;
use Simtabi\Laranail\Email\Resolvers\CachedDnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\DisposableDomainList;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\RoleAccountList;
use Simtabi\Laranail\Validation\Rules\Email\NotDisposableEmail;

/**
 * The point of this package: validation's rules keep working unchanged, but
 * now over maintained data.
 */
it('replaces every fallback validation binds', function (string $contract, string $expected): void {
    // validation binds its bundled fallbacks with singletonIf and this package
    // binds with singleton, so whichever provider boots second still leaves
    // the right implementation in place. The TestCase deliberately boots
    // validation FIRST — the order that would break if this package used
    // singletonIf too.
    expect(resolve($contract))->toBeInstanceOf($expected);
})->with([
    'disposable domains' => [DisposableDomainList::class, MaintainedDisposableDomainList::class],
    'role accounts' => [RoleAccountList::class, MaintainedRoleAccountList::class],
    'dns resolver' => [DnsResolver::class, CachedDnsResolver::class],
]);

it('makes validation’s email rules use the maintained lists', function (): void {
    // Not just "the binding is right" — the rule actually resolves through it.
    $rule = new NotDisposableEmail;

    expect(validator(['e' => 'alice@mailinator.com'], ['e' => [$rule]])->passes())->toBeFalse()
        ->and(validator(['e' => 'alice@example.com'], ['e' => [$rule]])->passes())->toBeTrue();
});

it('publishes its config under a namespaced tag', function (): void {
    expect(config('laranail.email.dns.positive_ttl'))->toBe(86400)
        ->and(config('laranail.email.dns.negative_ttl'))->toBe(300);
});

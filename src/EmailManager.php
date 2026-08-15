<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email;

use Simtabi\Laranail\Validation\Contracts\Email\DisposableDomainList;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\RoleAccountList;

/**
 * What the `Mail` facade resolves to.
 *
 * A thin front over the three collaborators — the disposable list, the role-account list and the DNS
 * resolver — so that callers have one place to start and {@see Email} stays what it is: a value
 * object that parses and nothing else.
 */
final readonly class EmailManager
{
    public function __construct(
        private DisposableDomainList $disposable,
        private RoleAccountList $roleAccounts,
        private DnsResolver $dns,
    ) {}

    /**
     * Start a chain.
     *
     * ```php
     * Mail::of('Alice+News@Example.COM')->canonical();   // 'alice@example.com'
     * ```
     */
    public function of(?string $address): EmailBuilder
    {
        return new EmailBuilder($address);
    }

    /** Parse without the chain, when the value object is all you want. */
    public function parse(?string $address): ?Email
    {
        return $address === null ? null : Email::parse($address);
    }

    /**
     * Canonicalise a list, keeping one entry per mailbox.
     *
     * The operation an import actually needs: `alice+news@example.com`, `Alice@Example.com` and
     * `alice@example.com` are one person signing up three times, and a naive `array_unique` keeps
     * all three.
     *
     * @param  iterable<string|null>  $addresses
     * @return list<string>
     */
    public function unique(iterable $addresses): array
    {
        $seen = [];

        foreach ($addresses as $address) {
            $canonical = $this->of($address)->canonical();

            if ($canonical !== null) {
                $seen[$canonical] = true;
            }
        }

        return array_keys($seen);
    }

    public function disposableList(): DisposableDomainList
    {
        return $this->disposable;
    }

    public function roleAccountList(): RoleAccountList
    {
        return $this->roleAccounts;
    }

    public function dns(): DnsResolver
    {
        return $this->dns;
    }
}

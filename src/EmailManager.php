<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email;

use Generator;
use Simtabi\Laranail\Email\Support\EmailAudit;
use Simtabi\Laranail\Email\Support\EmailAuditEntry;
use Simtabi\Laranail\Validation\Contracts\Email\DisposableDomainList;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\RoleAccountList;

/**
 * What the `Mail` facade resolves to.
 *
 * A thin front over four collaborators — the disposable list, the role-account list, the DNS
 * resolver and the batch pass — so that callers have one place to start and {@see Email} stays what
 * it is: a value object that parses and nothing else.
 */
final readonly class EmailManager
{
    public function __construct(
        private DisposableDomainList $disposable,
        private RoleAccountList $roleAccounts,
        private DnsResolver $dns,
        private EmailBatch $batch,
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
     * @param  iterable<mixed, string|null>  $addresses
     * @return list<string>
     */
    public function unique(iterable $addresses, bool $keepSubaddress = false): array
    {
        return $this->batch->unique($addresses, $keepSubaddress);
    }

    // ---------------------------------------------------------------- many at once

    /**
     * Judge a whole list in one pass.
     *
     * ```php
     * $audit = Mail::audit($rows, checkReachability: true);
     *
     * $audit->summary();     // ['total' => 4200, 'usable' => 3910, 'duplicates' => 61, …]
     * $audit->problems();    // ['role_account' => 180, 'disposable' => 74, 'unreachable' => 36]
     * $audit->distinct();    // the rows to keep
     * ```
     *
     * `checkReachability` groups its MX lookups per domain, so ten thousand addresses at one
     * provider cost one lookup rather than ten thousand.
     *
     * @param  iterable<mixed, string|null>  $addresses
     */
    public function audit(iterable $addresses, bool $checkReachability = false, bool $keepSubaddress = false): EmailAudit
    {
        return $this->batch->audit($addresses, $checkReachability, $keepSubaddress);
    }

    /**
     * The same pass, streamed. Nothing is accumulated, so the input may be larger than memory —
     * at the cost of per-domain reachability, which needs the whole list to group by.
     *
     * @param  iterable<mixed, string|null>  $addresses
     * @return Generator<int, EmailAuditEntry>
     */
    public function each(iterable $addresses, bool $keepSubaddress = false): Generator
    {
        return $this->batch->each($addresses, $keepSubaddress);
    }

    public function batch(): EmailBatch
    {
        return $this->batch;
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

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email;

use Generator;
use Simtabi\Laranail\Email\Enums\ScanLeniency;
use Simtabi\Laranail\Email\Support\EmailAudit;
use Simtabi\Laranail\Email\Support\EmailAuditEntry;
use Simtabi\Laranail\Email\Support\EmailAuditReport;
use Simtabi\Laranail\Email\Support\EmailMatch;
use Simtabi\Laranail\Validation\Contracts\Email\DisposableDomainList;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\RoleAccountList;

/**
 * What the `Mail` facade resolves to.
 *
 * A thin front over five collaborators — the disposable list, the role-account list, the DNS
 * resolver, the batch pass and the scanner — so that callers have one place to start and
 * {@see Email} stays what it is: a value object that parses and nothing else.
 */
final readonly class EmailManager
{
    public function __construct(
        private DisposableDomainList $disposable,
        private RoleAccountList $roleAccounts,
        private DnsResolver $dns,
        private EmailBatch $batch,
        private EmailScanner $scanner,
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

    /**
     * The verdict on a list of any size, without holding it.
     *
     * O(distinct) rather than O(n), so this is the one to reach for when the input is a database
     * column or a file rather than a form submission. No reachability — see {@see EmailBatch::report()}.
     *
     * @param  iterable<mixed, string|null>  $addresses
     */
    public function report(iterable $addresses, bool $keepSubaddress = false): EmailAuditReport
    {
        return $this->batch->report($addresses, $keepSubaddress);
    }

    public function batch(): EmailBatch
    {
        return $this->batch;
    }

    // ---------------------------------------------------------------- free text

    /**
     * Find every address inside a body of text.
     *
     * ```php
     * Mail::find($ticket);                              // addresses with real-looking domains
     * Mail::find($log, ScanLeniency::Possible);         // anything address-shaped
     * ```
     *
     * @return list<EmailMatch>
     */
    public function find(?string $text, ?ScanLeniency $leniency = null): array
    {
        return $this->scanner->scan($text, $leniency);
    }

    /**
     * Replace every address found, offsets handled.
     *
     * @param  callable(EmailMatch): string  $replace
     */
    public function replaceIn(?string $text, callable $replace, ?ScanLeniency $leniency = null): ?string
    {
        return $this->scanner->replace($text, $replace, $leniency);
    }

    /** Mask every address found, keeping the domain. */
    public function redact(?string $text, string $maskChar = '•', ?ScanLeniency $leniency = null): ?string
    {
        return $this->scanner->redact($text, $maskChar, $leniency);
    }

    public function scanner(): EmailScanner
    {
        return $this->scanner;
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

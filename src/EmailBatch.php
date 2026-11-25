<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email;

use Generator;
use Stringable;
use Simtabi\Laranail\Email\Support\EmailAudit;
use Simtabi\Laranail\Email\Support\EmailAuditEntry;
use Simtabi\Laranail\Email\Support\EmailAuditReport;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\RoleAccountList;
use Simtabi\Laranail\Validation\Contracts\Email\DisposableDomainList;

/**
 * Judges a list of addresses in one pass.
 *
 * The shape almost every real job arrives in: a CSV of signups, a mailing list someone is about to
 * send to, a users table nobody has looked at in three years. Asking the builder one address at a
 * time answers it, and answers it badly — see below.
 *
 * ## Reachability is resolved per domain, not per address
 *
 * This is the single reason batch reachability is usable at all. Ten thousand addresses at
 * `gmail.com` is **one** MX lookup here and ten thousand in the naive version; the resolver caches,
 * but a cache hit is still a call, and the first pass over a cold cache is not cheap either. A list
 * is overwhelmingly a handful of consumer providers plus a long tail, so the saving is not a corner
 * case — it is the ordinary shape of the data.
 *
 * It is still off by default. It is the only thing here that leaves the process, and a caller
 * iterating ten thousand rows should say so deliberately.
 *
 * ## Duplicate inputs are parsed once
 *
 * The reason to audit a list is that it is dirty, and a dirty list repeats itself — that is what an
 * audit is looking for. The pass memoises the parse by raw input, so the saving grows with exactly
 * the mess that made the audit necessary. The cache lives for the pass and is discarded with it.
 *
 * ## Two entry points, for two sizes of problem
 *
 * {@see audit()} holds every entry so the result can be filtered and reported on. {@see each()}
 * yields and accumulates nothing, for a file larger than memory — at the cost of the per-domain
 * batching above, which needs to see the whole list before it can group anything.
 */
final readonly class EmailBatch
{
    public function __construct(
        private DisposableDomainList $disposable,
        private RoleAccountList $roleAccounts,
        private DnsResolver $dns,
    ) {}

    /**
     * Parse and judge every input, and return the whole verdict.
     *
     * @param iterable<mixed, string|null> $inputs
     * @param bool $checkReachability Perform MX lookups, once per distinct domain
     * @param bool $keepSubaddress Treat `alice+news@` and `alice@` as different mailboxes
     */
    public function audit(iterable $inputs, bool $checkReachability = false, bool $keepSubaddress = false): EmailAudit
    {
        $rows = $this->rows($inputs, $keepSubaddress);

        $reachable = $checkReachability
            ? $this->reachabilityByDomain($rows)
            : [];

        $entries = [];
        $firstSeen = [];

        foreach ($rows as $index => [$input, $email, $canonical]) {
            $duplicateOf = null;

            if ($canonical !== null) {
                if (isset($firstSeen[$canonical])) {
                    $duplicateOf = $firstSeen[$canonical];
                } else {
                    $firstSeen[$canonical] = $index;
                }
            }

            $domain = $email?->domain;
            $isReachable = $checkReachability && $domain !== null ? ($reachable[$domain] ?? false) : null;

            $entries[] = new EmailAuditEntry(
                index: $index,
                input: $input,
                email: $email,
                canonical: $canonical,
                problems: $this->problems($email, $isReachable),
                reachable: $isReachable,
                duplicateOf: $duplicateOf,
            );
        }

        return new EmailAudit($entries, $checkReachability);
    }

    /**
     * The same judgement, streamed.
     *
     * Duplicate detection survives — it needs only the first index per canonical address, which is
     * O(distinct). Per-domain reachability does **not**: grouping by domain means seeing the whole
     * list first, and this method exists precisely because the list cannot be held. So reachability
     * here is per address and cached only by the resolver, which is why it is not offered at all
     * rather than offered badly.
     *
     * @param iterable<mixed, string|null> $inputs
     *
     * @return Generator<int, EmailAuditEntry>
     */
    public function each(iterable $inputs, bool $keepSubaddress = false): Generator
    {
        $parsed = [];
        $firstSeen = [];
        $index = 0;

        foreach ($inputs as $input) {
            $input = $this->stringify($input);

            $key = (string) $input;
            $email = $parsed[$key] ??= ($input === null || trim($input) === '' ? null : Email::parse($input));

            $canonical = $this->canonical($email, $keepSubaddress);
            $duplicateOf = null;

            if ($canonical !== null) {
                if (isset($firstSeen[$canonical])) {
                    $duplicateOf = $firstSeen[$canonical];
                } else {
                    $firstSeen[$canonical] = $index;
                }
            }

            yield new EmailAuditEntry(
                index: $index,
                input: $input,
                email: $email,
                canonical: $canonical,
                problems: $this->problems($email, null),
                duplicateOf: $duplicateOf,
            );

            $index++;
        }
    }

    /**
     * The report, without holding the entries.
     *
     * The third option, and usually the right one for anything large: `audit()` holds every entry at
     * O(n), `each()` holds nothing and gives up the report, and this holds only the tallies and the
     * first index per address — **O(distinct)**.
     *
     * No reachability, for the same reason `each()` has none: grouping MX lookups per domain means
     * seeing the whole list first, which is exactly what this method exists to avoid. The summary
     * says `checked_reachability: false` rather than leaving it to be inferred from an absent
     * `unreachable` count.
     *
     * @param iterable<mixed, string|null> $inputs
     */
    public function report(iterable $inputs, bool $keepSubaddress = false): EmailAuditReport
    {
        $report = new EmailAuditReport;

        foreach ($this->each($inputs, $keepSubaddress) as $entry) {
            $report->add($entry);
        }

        return $report;
    }

    /**
     * A list of whatever people typed, in: the distinct mailboxes it contains, out.
     *
     * The operation an import actually needs. `array_unique()` keeps all four spellings of one
     * mailbox, and a `SELECT DISTINCT` keeps them too.
     *
     * @param iterable<mixed, string|null> $inputs
     *
     * @return list<string>
     */
    public function unique(iterable $inputs, bool $keepSubaddress = false): array
    {
        $seen = [];

        foreach ($this->each($inputs, $keepSubaddress) as $entry) {
            if ($entry->canonical !== null && ! $entry->isDuplicate()) {
                $seen[] = $entry->canonical;
            }
        }

        return $seen;
    }

    /**
     * Parse every row once, keyed by index.
     *
     * @param iterable<mixed, string|null> $inputs
     *
     * @return list<array{0: string|null, 1: Email|null, 2: string|null}>
     */
    private function rows(iterable $inputs, bool $keepSubaddress): array
    {
        $parsed = [];
        $rows = [];

        foreach ($inputs as $input) {
            $input = $this->stringify($input);

            $key = (string) $input;
            $email = $parsed[$key] ??= ($input === null || trim($input) === '' ? null : Email::parse($input));

            $rows[] = [$input, $email, $this->canonical($email, $keepSubaddress)];
        }

        return $rows;
    }

    /**
     * One MX lookup per distinct domain, not one per address.
     *
     * @param list<array{0: string|null, 1: Email|null, 2: string|null}> $rows
     *
     * @return array<string, bool>
     */
    private function reachabilityByDomain(array $rows): array
    {
        $domains = [];

        foreach ($rows as [, $email]) {
            if ($email instanceof Email) {
                $domains[$email->domain] = true;
            }
        }

        $answers = [];

        foreach (array_keys($domains) as $domain) {
            $answers[$domain] = $this->dns->hasMailExchanger($domain);
        }

        return $answers;
    }

    /**
     * Everything wrong with one address, in the same vocabulary the builder uses.
     *
     * @return list<string>
     */
    private function problems(?Email $email, ?bool $reachable): array
    {
        if (! $email instanceof Email) {
            return ['unparseable'];
        }

        $problems = [];

        if ($this->disposable->contains($email->domain)) {
            $problems[] = 'disposable';
        }

        if ($this->roleAccounts->contains($email->mailbox())) {
            $problems[] = 'role_account';
        }

        if ($reachable === false) {
            $problems[] = 'unreachable';
        }

        return $problems;
    }

    private function canonical(?Email $email, bool $keepSubaddress): ?string
    {
        if (! $email instanceof Email) {
            return null;
        }

        return $keepSubaddress
            ? mb_strtolower($email->localPart) . '@' . $email->domain
            : $email->canonical();
    }

    private function stringify(mixed $input): ?string
    {
        if ($input === null) {
            return null;
        }

        return is_scalar($input) || $input instanceof Stringable ? (string) $input : null;
    }
}

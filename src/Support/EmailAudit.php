<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Simtabi\Laranail\Email\EmailBatch;
use Traversable;

/**
 * The verdict on a whole list of addresses.
 *
 * Two questions get asked of a batch and they are not the same question. *What is each of these*
 * produces one answer per input and scales with it. *What is wrong with this list* produces a report
 * whose size does not depend on the list's length at all — counts, the domain breakdown, the
 * duplicate groups, the problems tally. This object answers both, from one pass, so the two can
 * never disagree about the same input.
 *
 * ## Memory
 *
 * Entries are held, so this is O(n) in the input. Right for an import you are about to act on row by
 * row, wrong for a file larger than memory — {@see EmailBatch::each()} is
 * for that case.
 *
 * @implements IteratorAggregate<int, EmailAuditEntry>
 */
final readonly class EmailAudit implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  list<EmailAuditEntry>  $entries
     * @param  bool  $checkedReachability  Whether the pass performed MX lookups at all
     */
    public function __construct(
        public array $entries,
        public bool $checkedReachability = false,
    ) {}

    /**
     * @return list<EmailAuditEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<EmailAuditEntry>
     */
    public function usable(): array
    {
        return array_values(array_filter($this->entries, static fn (EmailAuditEntry $e): bool => $e->isUsable()));
    }

    /**
     * @return list<EmailAuditEntry>
     */
    public function unusable(): array
    {
        return array_values(array_filter($this->entries, static fn (EmailAuditEntry $e): bool => ! $e->isUsable()));
    }

    /**
     * @return list<EmailAuditEntry>
     */
    public function duplicates(): array
    {
        return array_values(array_filter($this->entries, static fn (EmailAuditEntry $e): bool => $e->isDuplicate()));
    }

    /**
     * The rows to keep when de-duplicating.
     *
     * The survivor of a duplicate group is deterministically the **earliest** row — the original
     * signup, not the re-registration.
     *
     * @return list<EmailAuditEntry>
     */
    public function distinct(): array
    {
        return array_values(array_filter($this->entries, static fn (EmailAuditEntry $e): bool => ! $e->isDuplicate()));
    }

    /**
     * Duplicate groups: canonical address => the input indexes that produced it.
     *
     * `alice@`, `Alice@` and `alice+news@` are one mailbox, so they are one group. That is the whole
     * reason to run this over a signup table.
     *
     * @return array<string, list<int>>
     */
    public function duplicateGroups(): array
    {
        $groups = [];

        foreach ($this->entries as $entry) {
            if ($entry->canonical === null) {
                continue;
            }

            $groups[$entry->canonical][] = $entry->index;
        }

        return array_filter($groups, static fn (array $indexes): bool => count($indexes) > 1);
    }

    /**
     * Every distinct canonical address, in first-seen order.
     *
     * @return list<string>
     */
    public function unique(): array
    {
        $seen = [];

        foreach ($this->entries as $entry) {
            if ($entry->canonical !== null) {
                $seen[$entry->canonical] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * Domain => how many rows use it, commonest first.
     *
     * The first thing to look at on an imported list: one domain holding most of the rows is either
     * a corporate export or a leak, and the two need different handling.
     *
     * @return array<string, int>
     */
    public function domains(): array
    {
        $counts = [];

        foreach ($this->entries as $entry) {
            $domain = $entry->domain();

            if ($domain === null) {
                continue;
            }

            $counts[$domain] = ($counts[$domain] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Problem => how many rows have it. A row with three problems is counted under all three.
     *
     * @return array<string, int>
     */
    public function problems(): array
    {
        $counts = [];

        foreach ($this->entries as $entry) {
            foreach ($entry->problems as $problem) {
                $counts[$problem] = ($counts[$problem] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * The fixed-size headline, whatever the input length.
     *
     * @return array{total: int, usable: int, unusable: int, unparseable: int, duplicates: int, distinct: int, domains: int, checked_reachability: bool}
     */
    public function summary(): array
    {
        $usable = count($this->usable());
        $duplicates = count($this->duplicates());

        return [
            'total' => count($this->entries),
            'usable' => $usable,
            'unusable' => count($this->entries) - $usable,
            'unparseable' => count(array_filter($this->entries, static fn (EmailAuditEntry $e): bool => ! $e->isParseable())),
            'duplicates' => $duplicates,
            'distinct' => count($this->entries) - $duplicates,
            'domains' => count($this->domains()),
            'checked_reachability' => $this->checkedReachability,
        ];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entries);
    }

    /**
     * The fixed-size verdict.
     *
     * Delegates to {@see EmailAuditReport} rather than computing its own, so this path and the
     * streaming one cannot drift into disagreeing about what a summary means.
     *
     * @return array{summary: array<string, bool|int>, domains: array<string, int>, problems: array<string, int>, duplicates: array<string, list<int>>}
     */
    public function report(): array
    {
        $report = new EmailAuditReport($this->checkedReachability);

        foreach ($this->entries as $entry) {
            $report->add($entry);
        }

        return $report->toArray();
    }

    /**
     * @return array{summary: array<string, bool|int>, domains: array<string, int>, problems: array<string, int>, duplicates: array<string, list<int>>, entries: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            ...$this->report(),
            'entries' => array_map(static fn (EmailAuditEntry $e): array => $e->toArray(), $this->entries),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

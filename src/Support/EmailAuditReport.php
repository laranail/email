<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Support;

use JsonSerializable;
use Simtabi\Laranail\Email\EmailBatch;

/**
 * The fixed-size verdict on a list, accumulated one entry at a time.
 *
 * ## The gap this closes
 *
 * {@see EmailAudit} holds every entry, so it can report *and* be filtered — at O(n) memory.
 * {@see EmailBatch::each()} holds nothing, so it scales to any input — and
 * gives up the report, because nothing can total a list it has already forgotten.
 *
 * That was a false choice. The report is fixed-size by construction: counts, tallies, and a bounded
 * sample of the row indexes per address. None of that needs the entries kept, and none of it grows
 * with the input.
 *
 * That last part took a correction. An earlier version kept **every** index per address, which is
 * O(rows) wearing an O(distinct) description: ten thousand rows over three addresses cost 1.2 MB.
 * The counts are exact and the indexes are sampled; see {@see $samples}.
 *
 * ## One definition of the report
 *
 * `EmailAudit::report()` delegates here rather than computing its own, so the two paths through the
 * package — hold everything, or stream — cannot drift into disagreeing about what a summary means.
 *
 * ## Reachability is declared, not inferred
 *
 * The per-domain MX grouping that makes {@see EmailBatch::audit()} usable
 * needs the whole list in hand before it can group anything — so the streaming path cannot do it,
 * and `checkedReachability` defaults to false there. It is a constructor argument rather than
 * something derived from the entries because an absent `unreachable` count means "nobody looked",
 * not "nothing was unreachable", and a report that cannot tell a reader which is misleading.
 *
 * Mutable, deliberately and alone in this package: it is an accumulator, and an immutable one would
 * allocate a new object per row.
 */
final class EmailAuditReport implements JsonSerializable
{
    /** How many example row indexes are kept per duplicate group. */
    public const int SAMPLE_LIMIT = 100;

    private int $total = 0;

    private int $usable = 0;

    private int $unparseable = 0;

    private int $duplicates = 0;

    /** @var array<string, int> */
    private array $domains = [];

    /** @var array<string, int> */
    private array $problems = [];

    /**
     * Canonical address => how many rows produced it. Exact, and O(distinct).
     *
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * Canonical address => up to {@see SAMPLE_LIMIT} of the input indexes that produced it.
     *
     * **Capped, and the cap is the difference between this class working and not.** Keeping every
     * index makes the structure O(rows) rather than O(distinct), which is exactly the cost the
     * streaming report exists to avoid.
     *
     * The counts above stay exact, so no total is ever wrong. What is bounded is how many example
     * rows a reader can be pointed at.
     *
     * @var array<string, list<int>>
     */
    private array $samples = [];

    public function __construct(private readonly bool $checkedReachability = false) {}

    public function add(EmailAuditEntry $entry): void
    {
        $this->total++;

        if ($entry->isUsable()) {
            $this->usable++;
        }

        if (! $entry->isParseable()) {
            $this->unparseable++;
        }

        if ($entry->isDuplicate()) {
            $this->duplicates++;
        }

        $domain = $entry->domain();

        if ($domain !== null) {
            $this->domains[$domain] = ($this->domains[$domain] ?? 0) + 1;
        }

        foreach ($entry->problems as $problem) {
            $this->problems[$problem] = ($this->problems[$problem] ?? 0) + 1;
        }

        if ($entry->canonical !== null) {
            $this->record($entry->canonical, $entry->index);
        }
    }

    /**
     * Fold another report into this one.
     *
     * For chunks audited separately — different workers, or a resumed job. Duplicate groups merge
     * correctly only because indexes are positions in the whole input rather than in the chunk.
     */
    public function merge(self $other): void
    {
        $this->total += $other->total;
        $this->usable += $other->usable;
        $this->unparseable += $other->unparseable;

        $this->domains = $this->addTallies($this->domains, $other->domains);
        $this->problems = $this->addTallies($this->problems, $other->problems);

        foreach ($other->counts as $canonical => $count) {
            $this->counts[$canonical] = ($this->counts[$canonical] ?? 0) + $count;
        }

        foreach ($other->samples as $canonical => $indexes) {
            foreach ($indexes as $index) {
                $this->sample($canonical, $index);
            }
        }

        // Recounted rather than added, because an address first seen in chunk one and again in chunk
        // two is a duplicate that neither chunk could see on its own.
        $this->duplicates = 0;

        foreach ($this->counts as $count) {
            $this->duplicates += max(0, $count - 1);
        }
    }

    /**
     * @return array{total: int, usable: int, unusable: int, unparseable: int, duplicates: int, distinct: int, domains: int, checked_reachability: bool}
     */
    public function summary(): array
    {
        return [
            'total'                => $this->total,
            'usable'               => $this->usable,
            'unusable'             => $this->total - $this->usable,
            'unparseable'          => $this->unparseable,
            'duplicates'           => $this->duplicates,
            'distinct'             => $this->total - $this->duplicates,
            'domains'              => count($this->domains),
            'checked_reachability' => $this->checkedReachability,
        ];
    }

    /**
     * Canonical address => exactly how many rows produced it, for the ones that repeat.
     *
     * Unlike {@see duplicateGroups()} these counts are never truncated.
     *
     * @return array<string, int>
     */
    public function duplicateCounts(): array
    {
        return array_filter($this->counts, static fn (int $count): bool => $count > 1);
    }

    /**
     * @return array<string, int>
     */
    public function domains(): array
    {
        return $this->sorted($this->domains);
    }

    /**
     * @return array<string, int>
     */
    public function problems(): array
    {
        return $this->sorted($this->problems);
    }

    /**
     * Canonical address => example input indexes, for the ones that repeat.
     *
     * A **sample**, capped at {@see SAMPLE_LIMIT} per group. {@see duplicateCounts()} carries the
     * exact totals.
     *
     * @return array<string, list<int>>
     */
    public function duplicateGroups(): array
    {
        return array_intersect_key($this->samples, $this->duplicateCounts());
    }

    /**
     * Every distinct canonical address, in first-seen order.
     *
     * @return list<string>
     */
    public function unique(): array
    {
        return array_keys($this->counts);
    }

    /**
     * @return array{summary: array<string, bool|int>, domains: array<string, int>, problems: array<string, int>, duplicates: array<string, list<int>>, duplicate_counts: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'summary'          => $this->summary(),
            'domains'          => $this->domains(),
            'problems'         => $this->problems(),
            'duplicates'       => $this->duplicateGroups(),
            'duplicate_counts' => $this->duplicateCounts(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function record(string $key, int $index): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        $this->sample($key, $index);
    }

    private function sample(string $key, int $index): void
    {
        $kept = $this->samples[$key] ?? [];

        if (count($kept) >= self::SAMPLE_LIMIT) {
            return;
        }

        $kept[] = $index;
        $this->samples[$key] = $kept;
    }

    /**
     * @param array<string, int> $into
     * @param array<string, int> $from
     *
     * @return array<string, int>
     */
    private function addTallies(array $into, array $from): array
    {
        foreach ($from as $key => $count) {
            $into[$key] = ($into[$key] ?? 0) + $count;
        }

        return $into;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function sorted(array $counts): array
    {
        arsort($counts);

        return $counts;
    }
}

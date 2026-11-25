<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Jobs;

use Generator;
use Illuminate\Bus\Queueable;
use InvalidArgumentException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\SerializesModels;
use Simtabi\Laranail\Email\EmailBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Simtabi\Laranail\Email\Support\EmailAuditReport;

/**
 * Audits a whole table column on the queue, in chunks, and caches the report.
 *
 * ```php
 * AuditEmailColumn::dispatch(User::class, 'email', key: 'users');
 *
 * // …later
 * Cache::get('laranail.email.audit.users');
 * ```
 *
 * ## Why the job takes a model class rather than the rows
 *
 * A queue payload has to serialise, and a generator does not. Neither does a closure, a database
 * cursor or an open file handle — which rules out every shape that makes streaming work in-process.
 * So the job carries a *description* of the source and opens it on the worker: a class name, a
 * column, and an optional named scope.
 *
 * The alternative — passing the rows in — reintroduces the problem the job exists to solve, since a
 * million values would have to be serialised into the payload before a worker ever saw one.
 *
 * ## One stream, not a merge per chunk
 *
 * `lazyById()` pages the query by primary key and yields models one at a time, so the whole column
 * reaches {@see EmailBatch::report()} as a **single** sequence. That matters for more than tidiness:
 * the batch numbers entries from zero as it consumes them, so auditing chunk-by-chunk and merging
 * the reports afterwards would give every chunk its own indexes starting at 0 — the duplicate groups
 * would collide across chunks and report rows as duplicates of each other that are nothing of the
 * kind. Streaming once removes the failure rather than compensating for it.
 *
 * ## No reachability
 *
 * Deliberately. Grouping MX lookups per domain needs the whole column in hand, which is the thing a
 * streamed audit does not have — so the report says `checked_reachability: false` rather than making
 * a million uncached DNS queries in order to look thorough.
 *
 * Keyset paging rather than `offset`, because an audit whose caller is also writing to the table it
 * reads — a de-duplication pass, say — shifts rows underneath an offset-paged query and silently
 * skips some.
 *
 * ## Memory
 *
 * O(distinct) in the addresses seen, not O(rows): the accumulator holds tallies and the first index
 * per address, never the entries. A million-row column of a few thousand distinct addresses costs
 * the few thousand. That is the whole reason {@see EmailAuditReport} exists separately from the audit.
 */
class AuditEmailColumn implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param class-string<Model> $model
     * @param string $column The column holding the addresses
     * @param bool $keepSubaddress Treat `alice+news@` and `alice@` as different mailboxes
     * @param string $key Cache key suffix the report is written under
     * @param int $chunk Rows per query
     * @param string|null $scope A named query scope to constrain the rows, applied without arguments
     * @param int|null $ttl Seconds to keep the report; null keeps it until evicted
     */
    public function __construct(
        public string $model,
        public string $column,
        public bool $keepSubaddress = false,
        public string $key = 'default',
        public int $chunk = 1000,
        public ?string $scope = null,
        public ?int $ttl = 86400,
    ) {}

    public function handle(EmailBatch $batch): void
    {
        $report = $batch->report($this->values(), $this->keepSubaddress);

        $this->publish($report);
    }

    /** The cache key the report lands under. */
    public function cacheKey(): string
    {
        return 'laranail.email.audit.' . $this->key;
    }

    /** The cache key carrying how many rows have been read so far. */
    public function progressKey(): string
    {
        return $this->cacheKey() . '.progress';
    }

    /**
     * The column, streamed, with progress published as it goes.
     *
     * @return Generator<int, string|null>
     */
    protected function values(): Generator
    {
        // Instantiated rather than checked with `is_subclass_of`, because an instance is what the
        // rest of this method needs anyway — and it fails the same way for a class that does not
        // exist, is abstract, or is not a model, instead of only the last of those.
        $instance = app($this->model);

        if (! $instance instanceof Model) {
            throw new InvalidArgumentException(
                "[{$this->model}] is not an Eloquent model. This job reads a column from a table, so "
                . 'it needs a model class rather than an arbitrary source.',
            );
        }

        $query = $instance->newQuery();

        if ($this->scope !== null) {
            // A named scope, applied without arguments. Anything richer would have to be a closure,
            // and a closure cannot be serialised into a queue payload.
            $scoped = $query->{$this->scope}();

            if (! $scoped instanceof Builder) {
                throw new InvalidArgumentException(
                    "The scope [{$this->scope}] on [{$this->model}] did not return a query builder. "
                    . 'A scope that returns anything else cannot be chained, and silently ignoring it '
                    . 'would audit the whole table while appearing to audit a subset.',
                );
            }

            $query = $scoped;
        }

        $read = 0;

        foreach ($query->lazyById($this->chunk) as $row) {
            $value = $row->getAttribute($this->column);

            yield is_scalar($value) ? (string) $value : null;

            $read++;

            // Published per chunk rather than per row: a cache write per row would cost more than
            // the audit does, and a progress bar that moves a thousand rows at a time is not a
            // worse progress bar.
            if ($read % $this->chunk === 0) {
                $this->publishProgress($read);
            }
        }

        $this->publishProgress($read);
    }

    private function publishProgress(int $rowsRead): void
    {
        Cache::put($this->progressKey(), $rowsRead, $this->ttl ?? 3600);
    }

    private function publish(EmailAuditReport $report): void
    {
        $payload = $report->toArray();

        $this->ttl === null
            ? Cache::forever($this->cacheKey(), $payload)
            : Cache::put($this->cacheKey(), $payload, $this->ttl);
    }
}

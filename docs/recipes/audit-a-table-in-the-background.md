# Audit a table in the background

A million-row column, on the queue, without holding it in memory.

## Dispatch it

```php
use Simtabi\Laranail\Email\Jobs\AuditEmailColumn;

AuditEmailColumn::dispatch(
    model: User::class,
    column: 'email',
    key: 'users',
);
```

```php
$report = Cache::get('laranail.email.audit.users');

$report['summary']['usable'];       // 943_112
$report['problems']['role_account'];  // 18_204
$report['domains'];                   // ['gmail.com' => 402_118, …]
```

Nothing else is needed. The job reads the column in chunks, streams it through the report, and caches
the result under the key you chose.

## Watch it run

```php
$read = Cache::get('laranail.email.audit.users.progress', 0);
$total = User::count();

$percent = $total === 0 ? 100 : (int) round($read / $total * 100);
```

Progress is written once per chunk rather than per row — a cache write per row would cost more than
the audit does.

## What it deliberately does not check

**Reachability.** The per-domain MX grouping that makes
[`Mail::audit()`](../tools/batch.md#reachability-is-per-domain) usable needs the whole list in hand,
which a streamed audit does not have. The summary says `checked_reachability: false` rather than
making a million uncached DNS queries in order to look thorough.

If you want deliverability over a table, do it as a separate pass over the **distinct domains** —
that is a few thousand lookups rather than a million, and it is the same saving by hand:

```php
$domains = array_keys(Cache::get('laranail.email.audit.users')['domains']);

foreach (array_chunk($domains, 100) as $chunk) {
    // your own job, one lookup per domain
}
```

## Narrow it

```php
AuditEmailColumn::dispatch(User::class, 'email', key: 'active', scope: 'subscribed');
```

The scope is a **named** query scope, applied without arguments. Anything richer would have to be a
closure, and a closure cannot be serialised into a queue payload — the same constraint that makes the
job take a model class rather than the rows.

> A scope that does not return a builder throws. Ignoring it would audit the whole table while
> appearing to audit a subset, and the report would look entirely plausible.

## Keeping subaddresses apart

```php
AuditEmailColumn::dispatch(User::class, 'email', keepSubaddress: true, key: 'tagged');
```

By default `alice+news@` and `alice@` count as one mailbox, which is what you want for finding
duplicate signups. Pass `keepSubaddress: true` when your product routes on the tag and the two are
genuinely different accounts.

## What the report costs

Memory is bounded, not proportional to the table. The accumulator holds the tallies and up to a
hundred example row indexes per repeated address — never the rows.

> The **counts** are exact. The row indexes under `duplicates` are a sample; `duplicate_counts` has
> the true totals.

## When a job is the wrong tool

For anything that fits in memory — a CSV somebody just uploaded, a few thousand rows — call
[`Mail::audit()`](../tools/batch.md) directly and get the entries and the per-domain reachability as
well as the report. The queue buys you scale and costs you both of those.

---

[← Docs index](../../README.md#documentation)

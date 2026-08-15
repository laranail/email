# Batch and audit

`Mail::audit()` — one pass over a whole list, answering both what each address is and what is wrong
with the list.

```php
use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;

$audit = EmailAddress::audit($rows, checkReachability: true);

$audit->summary();    // ['total' => 4200, 'usable' => 3910, 'duplicates' => 61, …]
$audit->problems();   // ['role_account' => 180, 'disposable' => 74, 'unreachable' => 36]
$audit->distinct();   // the rows to keep
```

Everything else in this package answers a question about **one** address. Almost every real job
arrives as a list: a CSV of signups, a mailing list someone is about to send to, a users table nobody
has looked at in three years.

Deliberately the same shape as [`laranail/phone`'s batch](https://opensource.simtabi.com/documentation/laranail/phone/tools/batch)
— the two packages judge the same kind of thing, and an import script written against one should read
against the other.

In this section: [Two questions](#two-questions-not-one) · [Entries](#entries) ·
[The report](#the-report) · [Reachability](#reachability-is-per-domain) ·
[Streaming](#streaming-a-list-larger-than-memory) · [Method reference](#method-reference)

## Two questions, not one

| | Answers | Output size |
|---|---|---|
| `Mail::audit()` → entries | *What is each of these?* | Grows with the input |
| `Mail::audit()` → report | *What is wrong with this list?* | Fixed, whatever the length |

Both come from the same pass over the same entries, so the two can never disagree about the same
input.

## Entries

```php
foreach ($audit as $entry) {
    $entry->index;         // position in the INPUT, and it survives filtering
    $entry->input;         // exactly what was supplied
    $entry->email;         // the Email value object, or null
    $entry->canonical;     // 'alice@example.com'
    $entry->problems;      // ['disposable', 'role_account'] — all of them, not the first
    $entry->reachable;     // true / false / null when it was not checked
    $entry->duplicateOf;   // the first index with the same canonical address, or null
}
```

`index` is the part that makes a report actionable. "42 unusable addresses" is not something anyone
can fix; "rows 7, 19 and 104" is.

### Duplicates are by mailbox, not by string

```php
EmailAddress::audit(['alice@example.com', 'Alice@Example.com', 'alice+news@example.com', 'bob@…']);

$audit->duplicateGroups();   // ['alice@example.com' => [0, 1, 2]]
$audit->distinct();          // indexes 0 and 3
```

Four rows, two people. A unique index on the raw column allows all four, and so does
`SELECT DISTINCT`. `duplicateOf` points at the **first** row, so de-duplicating is a filter and the
survivor is the original signup rather than the re-registration.

When the tag is meaningful to you — routing, per-signup tracking — keep it:

```php
EmailAddress::audit($rows, keepSubaddress: true);
```

That still lowercases, which is safe, and leaves `+tag` alone.

## The report

```php
$audit->summary();
// ['total' => 4200, 'usable' => 3910, 'unusable' => 290, 'unparseable' => 74,
//  'duplicates' => 61, 'distinct' => 4139, 'domains' => 812,
//  'checked_reachability' => true]

$audit->problems();   // ['role_account' => 180, 'disposable' => 74, 'unreachable' => 36]
$audit->domains();    // ['gmail.com' => 1902, 'example.co.ke' => 411, …] commonest first
```

**`domains()` is the first thing to look at.** One domain holding most of the rows is either a
corporate export or a leak, and the two need very different handling.

`checked_reachability` is in the summary because the absence of `unreachable` means two different
things — no unreachable addresses, or nobody looked — and a report that cannot tell them apart is
misleading.

## Reachability is per domain

This is the single reason batch reachability is usable at all.

```php
$audit = EmailAddress::audit($tenThousandGmailAddresses, checkReachability: true);
// one MX lookup, not ten thousand
```

A list is overwhelmingly a handful of consumer providers plus a long tail, so this is not a corner
case — it is the ordinary shape of the data. The resolver caches, but a cache hit is still a call,
and the first pass over a cold cache is not cheap either.

> It stays **off by default**. It is the only thing in this package that leaves the process, and a
> caller iterating ten thousand rows should say so deliberately.

Two limits worth stating plainly:

- An MX record means somebody accepts mail for the **domain**. It says nothing about whether *this
  mailbox* exists — that cannot be known without sending.
- A negative may be a transient outage. See [Resolver](resolver.md) for the asymmetric TTLs that
  exist because of it.

## Streaming a list larger than memory

```php
foreach (EmailAddress::each($millionRowIterator) as $entry) {
    if (! $entry->isUsable()) {
        fputcsv($rejects, [$entry->index, $entry->input, implode(',', $entry->problems)]);
    }
}
```

Duplicate detection survives — it needs only the first index per canonical address, which is
O(distinct). Two things do not:

- **The report.** Nothing can total a list it has already forgotten.
- **Per-domain reachability.** Grouping by domain means seeing the whole list first, and this method
  exists precisely because the list cannot be held. So it is not offered here rather than offered
  badly.

And the shortest useful thing:

```php
EmailAddress::unique($column);   // ['alice@example.com', 'bob@example.com']
```

## Method reference

| | |
|---|---|
| `Mail::audit(iterable, bool $checkReachability, bool $keepSubaddress)` | The whole verdict |
| `Mail::each(iterable, bool $keepSubaddress)` | The same pass, streamed |
| `Mail::unique(iterable, bool $keepSubaddress)` | Just the distinct mailboxes |
| `Mail::batch()` | The `EmailBatch` service, for injection |

On the audit:

| | |
|---|---|
| `entries()` · `usable()` · `unusable()` | The rows |
| `distinct()` · `duplicates()` · `duplicateGroups()` · `unique()` | De-duplication |
| `summary()` · `problems()` · `domains()` | The report |
| `report()` · `toArray()` · `jsonSerialize()` | Output |
| `count()` · `isEmpty()` · iteration | It is `Countable` and `IteratorAggregate` |

The same shapes are reachable over HTTP — see [HTTP API](api.md).

---

[← Docs index](../../README.md#documentation)

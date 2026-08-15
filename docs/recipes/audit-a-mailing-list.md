# Audit a mailing list

Judge a list before you send to it, and report what is wrong in terms someone can act on.

## Look before you send

```php
use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;

$audit = EmailAddress::audit($rows, checkReachability: true);

$audit->summary();
// ['total' => 4200, 'usable' => 3910, 'unusable' => 290, 'unparseable' => 74,
//  'duplicates' => 61, 'distinct' => 4139, 'domains' => 812,
//  'checked_reachability' => true]
```

Two numbers decide whether to send, and neither is "usable":

- **`domains` => 812** against 4,200 addresses is a normal consumer list. `domains` => 3 would mean a
  corporate export, and `domains` => 1 with 4,200 rows means somebody pasted a directory.
- **`duplicates` => 61** means the source is not what someone told you it was — and every duplicate
  is a person who gets the mail twice.

## Report the failures by cause

```php
$audit->problems();
// ['role_account' => 180, 'disposable' => 74, 'unreachable' => 36]
```

Three different problems needing three different responses: role accounts are shared inboxes and
usually should not receive a personal mail; disposable addresses are people who did not want to hear
from you; unreachable domains are bounces you have not paid for yet.

Hand back the addressable rows:

```php
foreach ($audit->unusable() as $entry) {
    $report[] = [
        'row' => $entry->index + 2,          // +2: header row, and humans count from one
        'value' => $entry->input,
        'problems' => $entry->problems,       // all of them, not the first
    ];
}
```

## Send to the survivors

```php
foreach ($audit->distinct() as $entry) {
    if (! $entry->isUsable()) {
        continue;
    }

    Subscriber::create([
        'email' => $entry->input,          // what they typed — this is what you send to
        'email_canonical' => $entry->canonical,   // what you compare and index on
    ]);
}
```

Keep both. The canonical form is for the unique index; the original is what the person recognises,
and it carries a `+tag` they may be filtering their own inbox by.

`distinct()` drops rows repeating an earlier one, and the survivor is deterministically the
**earliest** — the original signup, not the re-registration.

## The MX check is the expensive one

`checkReachability: true` is the only thing here that leaves the process. Over a batch it is grouped
**per domain**, so 1,902 Gmail addresses cost one lookup rather than 1,902 — which is what makes it
usable at list scale at all.

Two limits to state in whatever report you produce:

- An MX record means somebody accepts mail for the **domain**. It says nothing about whether the
  mailbox exists.
- A negative may be a transient outage rather than a dead domain. That is why the resolver caches
  failures for minutes and successes for a day — see [Resolver](../tools/resolver.md).

## When the file is bigger than memory

```php
foreach (EmailAddress::each($millionRowIterator) as $entry) {
    $entry->isUsable()
        ? Subscriber::create(['email' => $entry->input, 'email_canonical' => $entry->canonical])
        : fputcsv($rejects, [$entry->index, $entry->input, implode(',', $entry->problems)]);
}
```

Duplicate detection still works. The report does not, and neither does per-domain reachability —
grouping by domain means holding the list, which is the thing you cannot do here.

## Two things not to do

**Do not `array_unique()` the column.** It keeps all four spellings of one mailbox, and so does
`SELECT DISTINCT`. That is what `canonical` is for — see
[Deduplicate signups](deduplicate-signups.md).

**Do not treat "not disposable" as durable.** The list is a moving target and never complete. A false
means "not on the list", nothing more.

---

[← Docs index](../../README.md#documentation)

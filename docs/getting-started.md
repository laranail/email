# Getting started

Parse an address, judge it, deduplicate a list — the whole loop in one page.

## The one rule worth learning first

**Store what the user typed; compare on the canonical form.** `alice+news@example.com`,
`Alice@Example.com` and `alice@example.com` are one mailbox. The address they typed is what you send
to and show back to them; the canonical form is what you index and compare on.

Everything else here exists to get from one to the other honestly.

## Parsing

```php
use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;

$email = EmailAddress::of('Alice+Newsletter@Example.COM');

$email->localPart();   // 'Alice+Newsletter'
$email->domain();      // 'example.com'
$email->mailbox();     // 'Alice'
$email->tag();         // 'Newsletter'
$email->canonical();   // 'alice@example.com'
```

> The facade is named `Mail` inside its own namespace and is **not** a global alias — Laravel's own
> `Mail` facade owns that name, and claiming it would replace the mailer with something that cannot
> send. Alias it at the call site, as above.

Two decisions worth knowing:

- **The split is on the last `@`, not the first.** `"a@b"@example.com` is a legal address whose
  domain is `example.com`.
- **The domain is lowercased; the local part is not.** RFC 5321 §2.4 makes local parts
  case-sensitive. Most providers ignore case, but that is their policy, not the standard, and a
  library that lowercases silently is deciding on their behalf.

## Junk never throws

```php
EmailAddress::of('not an address')->isParseable();   // false
EmailAddress::of(null)->canonical();                  // null
```

An unparseable address is a fact to report, not an exception to raise — the input that could not be
parsed is exactly the input a form submitted.

## Judging it

```php
EmailAddress::of($a)->isDisposable();    // a known throwaway provider
EmailAddress::of($a)->isRoleAccount();   // info@, support@, noreply@
EmailAddress::of($a)->problems();        // ['disposable'] — everything, at once
EmailAddress::of($a)->isUsable();
```

`problems()` returns the whole set rather than the first failure, because a form that reports one
problem per submission makes the user discover them one round trip at a time.

The MX check is separate and **off by default** — it is the only thing here that leaves the process:

```php
EmailAddress::of($a)->problems(checkReachability: true);   // ['disposable', 'unreachable']
```

## More than one at a time

Almost every real job is a list rather than a field:

```php
$audit = EmailAddress::audit($csvColumn, checkReachability: true);

$audit->summary();    // ['total' => 4200, 'usable' => 3910, 'duplicates' => 61, …]
$audit->problems();   // ['role_account' => 180, 'disposable' => 74]
$audit->domains();    // ['gmail.com' => 1902, …] — the first thing to look at
$audit->distinct();   // the rows to keep, earliest-wins
```

Reachability over a batch is resolved **per domain**, so ten thousand addresses at one provider cost
one lookup. See [Batch and audit](tools/batch.md).

## Validating it

Validation lives in `laranail/validation`, not here — one rule, one home:

```php
use Simtabi\Laranail\Validation\FluentRule;

'email' => FluentRule::email()->required()->notDisposable()->notRole(),
```

Installing this package swaps maintained lists and a production resolver in behind those rules,
through contracts, so no rule and no call site changes. See [Architecture](architecture.md).

## Testing

Never make a DNS query in a test:

```php
use Simtabi\Laranail\Email\Testing\FakeDnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;

$dns = FakeDnsResolver::deliverable('example.com');
$this->app->instance(DnsResolver::class, $dns);

expect($dns->wasAsked('example.com'))->toBeTrue();
```

## Where to go next

- [Fluent builder](tools/fluent-builder.md) — the whole chain, and what `canonical()` decides
- [Batch and audit](tools/batch.md) — judging a list, and per-domain reachability
- [Lists](tools/lists.md) — what is in them, and how the refresh protects itself
- [HTTP API](tools/api.md) — reaching all of it from something that is not PHP
- [Architecture](architecture.md) — why the rules live in another package

---

[← Docs index](../README.md#documentation)

# Fluent builder

`Mail::of(...)` — say what you have, then ask what you want.

```php
use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;

EmailAddress::of('Alice+News@Example.COM')->canonical();   // 'alice@example.com'
EmailAddress::of($address)->isDisposable();
EmailAddress::of($address)->problems();                     // everything wrong, at once
```

> The facade is named `Mail` inside its own namespace and is **not** registered as a global alias —
> Laravel's own `Mail` facade owns that name, and claiming it would replace the mailer with something
> that cannot send. Alias it at the call site.

Shaped deliberately like [`laranail/phone`'s builder](https://opensource.simtabi.com/documentation/laranail/phone/tools/fluent-builder).
The two packages solve the same class of problem — a contact identifier that arrives as a string, is
dirtier than it looks, and needs normalising, judging and storing — and a developer who has learned
one should not have to learn the other.

In this section: [Parsing](#parsing) · [Canonicalisation](#canonicalisation) ·
[Judgements](#judgements) · [Method reference](#method-reference)

## Parsing

```php
$email = EmailAddress::of('Alice+Newsletter@Example.COM');

$email->localPart();   // 'Alice+Newsletter'
$email->domain();      // 'example.com'
$email->mailbox();     // 'Alice'   — the tag removed, case kept
$email->tag();         // 'Newsletter'
$email->value();       // the Email value object
```

Two decisions worth knowing:

- **The split is on the last `@`, not the first.** `"a@b"@example.com` is a legal address whose
  domain is `example.com`; splitting on the first `@` gets both halves wrong.
- **The domain is lowercased; the local part is not.** Domains are case-insensitive by DNS. Local
  parts are case-**sensitive** per RFC 5321 §2.4 — most providers ignore case, but that is their
  policy, not the standard, and a library that lowercases silently is deciding on their behalf.

Nothing throws. An unparseable string yields a builder whose reads return null:

```php
EmailAddress::of('not an address')->isParseable();   // false
EmailAddress::of(null)->canonical();                  // null
```

## Canonicalisation

`canonical()` is where the policy lives, named rather than implicit:

```php
EmailAddress::of('Alice+News@Example.COM')->canonical();   // 'alice@example.com'
```

Lowercased, and with the `+tag` removed. For deduplication `alice+news@` and `alice@` are one
mailbox, and treating them otherwise is how one person signs up eleven times.

When the tag is meaningful to you — routing, per-signup tracking — keep it:

```php
EmailAddress::of('alice+news@example.com')->keepSubaddress()->canonical();
// 'alice+news@example.com'
```

Narrowing returns a **new** instance, so a builder held on a model or passed into a view cannot be
changed underneath whoever is holding it.

### Comparing and deduplicating

```php
EmailAddress::of('alice+news@example.com')->equals('ALICE@EXAMPLE.COM');   // true

EmailAddress::unique([
    'alice@example.com', 'Alice@Example.com', 'alice+news@example.com', 'bob@example.com',
]);
// ['alice@example.com', 'bob@example.com']
```

`unique()` is the operation an import actually needs. `array_unique()` keeps all four.

## Judgements

```php
EmailAddress::of($a)->isDisposable();    // a known throwaway provider
EmailAddress::of($a)->isRoleAccount();   // info@, support@, noreply@
EmailAddress::of($a)->isReachable();     // the domain has an MX record, cached
```

**`problems()` returns all of them at once**, because a form that reports one failure per submission
makes the user discover them one round trip at a time:

```php
EmailAddress::of($a)->problems();                            // ['disposable']
EmailAddress::of($a)->problems(checkReachability: true);      // ['disposable', 'unreachable']
EmailAddress::of($a)->isUsable();                             // problems() === []
```

> The MX lookup is **off by default** and the only check here that leaves the process. A caller
> iterating ten thousand addresses should opt into that deliberately.

Two honest limits:

- A disposable-domain list is a moving target and never complete. A false means "not on the list",
  never "durable".
- An MX record means somebody accepts mail for the **domain**. It says nothing about whether *this
  mailbox* exists — that cannot be known without sending.

## Method reference

| | |
|---|---|
| `of(?string)` · `keepSubaddress(bool)` | Entry and narrowing |
| `value()` · `isParseable()` | The `Email` value object |
| `localPart()` · `domain()` · `mailbox()` · `tag()` | The parts |
| `canonical()` · `equals()` · `isAt()` | Comparison |
| `isDisposable()` · `isRoleAccount()` · `isReachable()` | Judgements |
| `problems(bool)` · `isUsable(bool)` | All of them at once |
| `toArray()` · `jsonSerialize()` · `__toString()` | Output |

Validation **rules** are not here. They live in `laranail/validation` and use this underneath — the
question "is this acceptable" belongs to a form; the questions here are "what is this" and "what is
wrong with it".

---

[← Docs index](../../README.md#documentation)

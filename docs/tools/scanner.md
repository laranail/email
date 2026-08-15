# Scanner

`Mail::find()` — where the addresses are in a body of text, and what they are.

```php
use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;

EmailAddress::find('Write to alice@example.com before Friday.');
// [EmailMatch { raw: 'alice@example.com', offset: 9, email: Email }]

EmailAddress::redact($supportTicket);
// 'contact a••••@example.com now'
```

Parsing answers "is this string an address". This answers "which parts of this string are" — the
question a support ticket, a CV, a scraped page or a chat log actually poses.

In this section: [What it can claim](#what-it-can-claim-and-what-it-cannot) ·
[Leniency](#leniency) · [Offsets](#offsets-and-replacement) · [Redaction](#redaction) ·
[Method reference](#method-reference)

## What it can claim, and what it cannot

This is the honest part, and it is worth stating before the API.

[`laranail/phone`'s scanner](https://opensource.simtabi.com/documentation/laranail/phone/tools/scanner)
is backed by the world's numbering plans: it can reject a candidate on the grounds that no country
issues a number shaped like it, which is how it tells a phone number from an invoice reference.
**There is no equivalent authority for email.** Any address-shaped string might be a real mailbox.

So what is here is a deliberately narrow pattern, every candidate re-parsed through the same
`Email::parse()` the rest of the package uses, and a leniency ladder whose rungs are stated rather
than implied. A match means *"this is address-shaped, and cleared the bar you asked for"* — no more.

> A full RFC 5322 regex is famously enormous and still wrong: it accepts quoted local parts, comments
> and folding whitespace that no human writes in prose and no form accepts. Matching that grammar in
> a document would find **more** false positives, not fewer.

## Leniency

```php
use Simtabi\Laranail\Email\Enums\ScanLeniency;

EmailAddress::find($text, ScanLeniency::Possible);
```

| | Accepts | Reach for it when |
|---|---|---|
| `Possible` | anything shaped `local@domain` | Redacting — a false positive costs a blacked-out word, a false negative leaks a real address |
| `Valid` *(default)* | the domain has a dot and a plausible TLD | Extracting contacts from prose |
| `Deliverable` | as `Valid`, and the domain has an MX record | You are about to send, and want the strongest claim available |

`Valid` is the default because of one case: `ssh deploy@web-01` is a command, not a contact. A
single-label host is a real address on a private network and noise in a marketing list, and the
default should suit the second.

`Deliverable` is the only rung that touches the network. Lookups are cached and resolved **once per
distinct domain per scan**, so a page of forty addresses across three providers costs three. It still
says nothing about whether a particular mailbox exists — that cannot be known without sending.

## Offsets and replacement

Every match carries a **byte** offset, so a caller can highlight, redact or link in place rather than
searching the text again — which finds the *first* occurrence rather than *this* one:

```php
$text = 'alice@example.com wrote; reply to alice@example.com';

EmailAddress::replaceIn($text, fn ($m) => "<a href=\"mailto:{$m->email}\">{$m->raw}</a>");
```

Replacement walks the matches in reverse, so replacing one does not move the offsets of the ones
before it. That is the bug every hand-rolled version of this has, and it only shows up on the second
address in a document.

### Boundaries the pattern gets right

| Input | Match |
|---|---|
| `Email me at alice@example.com.` | `alice@example.com` — the full stop is the sentence's |
| `(alice@example.com)` | `alice@example.com` |
| `Contact: <alice@example.com>` | `alice@example.com` |
| `id x-alice@example.com here` | `x-alice@example.com`, not a match starting mid-word |
| `alice+support@example.com` | kept whole; the tag is part of the address |
| `bump to service.v2` | nothing |
| `mention @example` | nothing |

A domain may legally end in a dot — the DNS root — so the pattern cannot tell that apart from a full
stop. A person writing prose never means the root.

## Redaction

```php
EmailAddress::redact('contact alice@example.com now');
// 'contact a••••@example.com now'
```

**The domain survives on purpose.** Knowing a complaint came from a `gov.uk` address, or that forty
of them came from one provider, is often the reason the log is being read at all.

`redact()` defaults to `Possible` where everything else defaults to `Valid`. When redacting the
asymmetry runs the other way — over-matching costs a blacked-out word, under-matching leaks a real
address — so the default follows it.

## Method reference

| | |
|---|---|
| `Mail::find(?string, ?ScanLeniency)` | The matches, in order |
| `Mail::replaceIn(?string, callable, ?ScanLeniency)` | Replace each, offsets handled |
| `Mail::redact(?string, string, ?ScanLeniency)` | Mask the mailbox, keep the domain |
| `Mail::scanner()` | The `EmailScanner` service, for injection |

On a match: `raw`, `offset`, `email`, `end()`, `toArray()`.

Defaults come from `laranail.email.scanning` — see [Configuration](../configuration.md).

---

[← Docs index](../../README.md#documentation)

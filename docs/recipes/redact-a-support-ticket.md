# Redact a support ticket

Take the addresses out of text you are about to share, without taking out the part that makes the
text useful.

## The one-liner

```php
use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;

EmailAddress::redact($ticket->body);
// 'Customer a••••@example.com says the a•••••@example.com alias bounces.'
```

Both addresses go, and the domain stays on each. That is deliberate: knowing a complaint came from a
`gov.uk` address, or that forty of them came from one provider, is often the whole reason someone is
reading the log.

## Why it over-matches on purpose

`redact()` defaults to the widest leniency, where everything else in the package defaults to the
middle one. The asymmetry runs the other way here:

| | Cost of a false positive | Cost of a false negative |
|---|---|---|
| Extracting contacts | a bad address in your list | one contact missed |
| **Redacting** | a blacked-out word | **a real address leaked** |

So `deploy@web-01` in a pasted command gets masked too. That is the right trade for a document about
to leave the building, and the wrong one for harvesting — which is why the default differs rather
than being one setting for both.

## Keeping the redaction reversible

`replaceIn()` gives you the match, so you can substitute a token you can map back:

```php
$tokens = [];

$safe = EmailAddress::replaceIn($ticket->body, function ($match) use (&$tokens): string {
    $token = '{email:'.count($tokens).'}';
    $tokens[$token] = $match->raw;

    return $token;
}, ScanLeniency::Possible);
```

Store `$tokens` where the redacted copy does not go. Do not derive the token from the address — a
hash of it is a lookup table away from the original for any address you can guess, which is most of
them.

## Redacting the right occurrence

An address usually appears more than once in a ticket. Matches carry byte offsets and replacement
walks them in reverse, so replacing one does not move the ones before it:

```php
$text = 'alice@example.com wrote; reply to alice@example.com';

EmailAddress::replaceIn($text, fn ($m) => '[redacted]');
// '[redacted] wrote; reply to [redacted]'
```

A hand-rolled `str_replace` over the matched string gets this right by accident and a
`substr_replace` in forward order gets it wrong — the second replacement lands at an offset the first
one moved.

## Bulk, over a table

```php
Ticket::where('archived', false)->lazyById()->each(function (Ticket $ticket): void {
    $ticket->update(['body' => EmailAddress::redact($ticket->body)]);
});
```

> Redaction is not reversible once written back. Copy the column before a bulk pass, or write to a
> second column — a scanner tuned to over-match will occasionally take a word you wanted.

## What this cannot do

It finds address-*shaped* text. It cannot find an address somebody spelled out — "alice at example
dot com" — and no pattern reasonably can. If that matters, the answer is a human reading the
document, and the scanner's job is to reduce how much they have to read rather than to replace them.

---

[← Docs index](../../README.md#documentation)

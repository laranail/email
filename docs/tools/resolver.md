# Resolver

`CachedDnsResolver` answers whether a domain accepts mail, for
`laranail/validation`'s `Network\DeliverableEmail`.

## What it checks

An MX record, then — per RFC 5321 §5.1 — an A or AAAA record, because a domain with an address
record and no MX still takes delivery and small domains rely on it. Checking MX alone rejects
real mailboxes.

It does **not** check that a mailbox exists. Only an SMTP conversation establishes that, most
providers now answer it dishonestly to defeat harvesting, and running one from a signup form is
a good way to get the sending host blocked.

## Caching

| Setting | Default | Why |
|---|---|---|
| `dns.positive_ttl` | 86400 | A domain that resolves today will resolve tomorrow |
| `dns.negative_ttl` | 300 | A negative may only be a transient outage; caching it for a day turns a blip into a day of rejected signups |
| `dns.store` | the default store | Point lookups at a shared store; an array store is empty every request |

The providers most addresses belong to — Gmail, Outlook, Yahoo, iCloud, Proton — short-circuit
without a lookup or a cache entry. They will not stop accepting mail while a request is in
flight, and skipping them removes most of the traffic.

Internationalised domains are punycoded before the lookup. Without that every IDN looks like a
lookup failure and is waved through by the uncertainty rule below — passing for the wrong
reason.

## A failed lookup passes

This is specified in the contract, not chosen here. See
[Architecture](../architecture.md#why-a-failed-dns-lookup-passes).

## Testing

Never make a DNS query in a test:

```php
use Simtabi\Laranail\Email\Testing\FakeDnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;

$dns = FakeDnsResolver::deliverable('example.com');
$this->app->instance(DnsResolver::class, $dns);
```

`FakeDnsResolver::everything()` and `::nothing()` cover the two blanket cases. `asked()` and
`wasAsked()` assert what was looked up — useful for proving a precognitive request performed no
lookup at all.

---

[← Docs index](../../README.md#documentation)

# laranail/email

[![Latest version on Packagist](https://img.shields.io/packagist/v/laranail/email.svg)](https://packagist.org/packages/laranail/email)
[![Tests](https://github.com/laranail/email/actions/workflows/run-tests.yml/badge.svg)](https://github.com/laranail/email/actions/workflows/run-tests.yml)
[![Static analysis](https://github.com/laranail/email/actions/workflows/phpstan.yml/badge.svg)](https://github.com/laranail/email/actions/workflows/phpstan.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> Email utilities for Laravel — a fluent API over an address value object that parses correctly, from one address to a whole list: canonicalisation for deduplication, maintained disposable and role-account lists, a cached deliverability resolver, batch auditing and an opt-in HTTP API.

Targets PHP `^8.4.1` on Laravel `^13`.

This package holds the **data and the IO**. The validation **rules** live in
[`laranail/validation`](https://github.com/laranail/validation) and work without this package,
over small bundled fallbacks. Installing this one swaps in maintained lists and a production
resolver — through contracts, so no rule and no call site changes.

## Install

```bash
composer require laranail/email
```

Then schedule the refresh, because a frozen list decays quietly — it keeps working, it just
stops catching anything new:

```php
// routes/console.php
Schedule::command('laranail::email.refresh-lists')->weekly();
```

## Quick start

```php
use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;

$email = EmailAddress::of('Alice+Newsletter@Example.COM');

$email->localPart();   // 'Alice+Newsletter'  — case preserved: local parts are case-sensitive
$email->domain();      // 'example.com'       — lowercased: domains are not
$email->mailbox();     // 'Alice'
$email->tag();         // 'Newsletter'
$email->canonical();   // 'alice@example.com' — one form per mailbox, for deduplication
$email->problems();    // everything wrong with it, at once
```

And for a list, which is how the job usually arrives:

```php
$audit = EmailAddress::audit($csvColumn, checkReachability: true);

$audit->summary();    // ['total' => 4200, 'usable' => 3910, 'duplicates' => 61, …]
$audit->problems();   // ['role_account' => 180, 'disposable' => 74]
$audit->distinct();   // the rows to keep
```

And in free text, which is where addresses turn up when nobody put them in a field:

```php
EmailAddress::find($supportTicket);    // matches, with byte offsets
EmailAddress::redact($supportTicket);  // 'contact a••••@example.com now'
```

Reachability over a batch is resolved **per domain**, so ten thousand addresses at one provider cost
one MX lookup rather than ten thousand.

The validation rules are unchanged and come from `laranail/validation`:

```php
'email' => FluentRule::email()->required()->notDisposable()->notRole(),
'email' => ['required', 'email', new DeliverableEmail()],
```

## Testing

Never make a DNS query in a test:

```php
use Simtabi\Laranail\Email\Testing\FakeDnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;

$dns = FakeDnsResolver::deliverable('example.com');
$this->app->instance(DnsResolver::class, $dns);

// ... exercise the code ...

expect($dns->wasAsked('example.com'))->toBeTrue();
```

## <a name="documentation"></a>Documentation

Full documentation is at
**[opensource.simtabi.com/documentation/laranail/email](https://opensource.simtabi.com/documentation/laranail/email/)**.

### Guides

- [Installation](docs/installation.md) — install, publish, and schedule the refresh
- [Getting started](docs/getting-started.md) — parse, judge and deduplicate, end to end
- [Configuration](docs/configuration.md) — four blocks, and the one that is a security decision
- [Architecture](docs/architecture.md) — why the rules live in another package, and the binding asymmetry
- [Release](docs/release.md) — versioning, tagging, and moving in step with `laranail/validation`

### Reference

- [Fluent builder](docs/tools/fluent-builder.md) — `Mail::of(...)`, canonicalisation, and all the problems at once
- [Batch and audit](docs/tools/batch.md) — judging a whole list, per-domain reachability, and the queued job
- [Scanner](docs/tools/scanner.md) — finding addresses in free text, and what a match can honestly claim
- [HTTP API](docs/tools/api.md) — three endpoints, off by default, and how to turn them on safely
- [Lists](docs/tools/lists.md) — disposable domains, role accounts, refreshing, and the fallback
- [Resolver](docs/tools/resolver.md) — caching, TTL asymmetry, and what a failed lookup means

### Recipes

- [Deduplicate signups](docs/recipes/deduplicate-signups.md) — one person, four addresses, one mailbox
- [Audit a mailing list](docs/recipes/audit-a-mailing-list.md) — judge a list before you send to it
- [Audit a table in the background](docs/recipes/audit-a-table-in-the-background.md) — a million rows on the queue
- [Redact a support ticket](docs/recipes/redact-a-support-ticket.md) — take the addresses out of text you are about to share

### Project

- [Changelog](CHANGELOG.md) · [Contributing](CONTRIBUTING.md) · [Security](SECURITY.md) · [Code of conduct](CODE_OF_CONDUCT.md) · [Credits](CREDITS.md)

## Stability

Pre-1.0. Constrain to `^0.1`.

## Local development

```bash
composer install
composer test
composer phpstan
composer format
```

## Sister packages

| Package | What it owns |
|---|---|
| [`laranail/validation`](https://github.com/laranail/validation) | The rules, the contracts, and the bundled fallbacks |

## Community

Questions and ideas in [Discussions](https://github.com/laranail/email/discussions); bugs in
[Issues](https://github.com/laranail/email/issues).

## Contributing & security

See [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities per [SECURITY.md](SECURITY.md)
(opensource@simtabi.com); participation follows the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

MIT © Simtabi LLC. See [LICENSE](LICENSE).

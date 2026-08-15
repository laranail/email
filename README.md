# laranail/email

[![Latest version on Packagist](https://img.shields.io/packagist/v/laranail/email.svg)](https://packagist.org/packages/laranail/email)
[![Tests](https://github.com/laranail/email/actions/workflows/run-tests.yml/badge.svg)](https://github.com/laranail/email/actions/workflows/run-tests.yml)
[![Static analysis](https://github.com/laranail/email/actions/workflows/phpstan.yml/badge.svg)](https://github.com/laranail/email/actions/workflows/phpstan.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> Email utilities for Laravel — an address value object that parses correctly, maintained disposable and role-account lists, and a cached deliverability resolver.

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
use Simtabi\Laranail\Email\Email;

$email = Email::parse('Alice+Newsletter@Example.COM');

$email->localPart;   // 'Alice+Newsletter'  — case preserved: local parts are case-sensitive
$email->domain;      // 'example.com'       — lowercased: domains are not
$email->mailbox();   // 'alice'
$email->tag();       // 'Newsletter'
$email->canonical(); // 'alice@example.com' — one form per mailbox, for deduplication
$email->isAt('example.com');           // true, and true for any subdomain
$email->equals('ALICE@example.com');   // true
```

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
- [Architecture](docs/architecture.md) — why the rules live in another package, and the binding asymmetry

### Reference

- [Lists](docs/tools/lists.md) — disposable domains, role accounts, refreshing, and the fallback
- [Resolver](docs/tools/resolver.md) — caching, TTL asymmetry, and what a failed lookup means

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

# Installation

Install the package, then schedule the refresh.

```bash
composer require laranail/email
```

Requires PHP 8.4.1+ and Laravel 13.

> Pre-1.0. The public surface is settling, so pin a constraint you are happy to review —
> `^0.1` tracks the current line.

## What installing it changes

Nothing you call. `laranail/validation` already provides the email rules and works without this
package, over small bundled fallbacks. This package binds maintained implementations over the
same contracts, so existing rules pick them up with no change to any call site.

| Contract | Without this package | With it |
|---|---|---|
| `DisposableDomainList` | a bundled snapshot | a refreshable list, subdomain-aware |
| `RoleAccountList` | a short list | RFC 2142 plus departmental, extensible from config |
| `DnsResolver` | one-hour cache, single TTL | asymmetric TTLs, configurable store, provider short-circuit |

## Schedule the refresh

A frozen list decays quietly: it keeps working, it just stops catching anything new, which is
the kind of failure nobody notices.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('laranail::email.refresh-lists')->weekly();
```

Run it once by hand to confirm it can write:

```bash
php artisan laranail::email.refresh-lists --dry-run
php artisan laranail::email.refresh-lists
```

Until it has run, the bundled snapshot is used. That is deliberate — a list that quietly
becomes empty is worse than no list, because the application still believes it is filtering.

## Publish the config

```bash
php artisan vendor:publish --tag=laranail::email-config
```

That writes **`config/laranail/email.php`** — a nested path, which matters: Laravel keys config by
filename, so a flat `config/laranail-email.php` would load under `laranail-email` and the package,
which reads `laranail.email.*`, would never see it. The published file is merged back over the
packaged defaults at boot, so a partially-edited copy still inherits everything it does not mention.

See [Configuration](configuration.md) for what is in it.

## The HTTP API is off

Installing this package adds **no routes**. If you want the analyse / batch / audit endpoints, enable
them deliberately and authenticate them — see [HTTP API](tools/api.md).

---

[← Docs index](../README.md#documentation)

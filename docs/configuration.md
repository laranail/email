# Configuration

Five blocks. Every default is safe to leave alone except the HTTP API, which is off and should stay
off until someone has decided how it will be authenticated.

Publish with `php artisan vendor:publish --tag=laranail::email-config`, which writes
`config/laranail/email.php`. Everything resolves under `config('laranail.email.*')`.

> The published path is **nested**, and that is load-bearing: Laravel keys config by filename, so a
> flat `config/laranail-email.php` would load under `laranail-email` and nothing would ever read it.
> An earlier version published exactly that, and the packaged defaults kept answering while the
> edited file sat unread. `laranail/package-tools` now owns both the path and merging the published
> file back over the defaults.

> There are no closures anywhere in the shipped config. A closure in a config file breaks
> `config:cache`, and the failure shows up at deploy time rather than in development.

## Reference

| Key | Default | What it does |
|---|---|---|
| `lists.path` | `null` | Where the refreshed disposable list is written; null uses the package's storage |
| `lists.source` | the CC0 upstream | Where `refresh-lists` fetches from |
| `role_accounts` | `[]` | Added to the RFC 2142 mailboxes already known |
| `dns.store` | `null` | Which cache store holds MX answers; null uses the default |
| `dns.positive_ttl` | `86400` | How long a resolving domain stays cached |
| `dns.negative_ttl` | `300` | How long a failing one does — deliberately shorter |
| `scanning.leniency` | `'VALID'` | How readily free-text scanning accepts a candidate |
| `scanning.limit` | `PHP_INT_MAX` | A ceiling on matches per scan |
| `api.enabled` | `false` | **No routes exist until this is true** |
| `api.prefix` | `api/laranail/email` | Where the endpoints mount |
| `api.middleware` | `['api']` | Not authentication — see below |
| `api.throttle` | `'60,1'` | Appended unless the middleware already throttles; null opts out |
| `api.max_batch` | `1000` | Enforced with a 422, never a truncation |
| `api.allow_reachability` | `true` | Whether a request may ask for MX lookups, including a `DELIVERABLE` scan |

## `lists`

```php
'lists' => [
    'path' => env('LARANAIL_EMAIL_LISTS_PATH'),
    'source' => env('LARANAIL_EMAIL_LISTS_SOURCE', '…/disposable_email_blocklist.conf'),
],
```

Until `laranail::email.refresh-lists` has run — or if the path is not writable — the snapshot shipped
with the package is used, so the rules keep working rather than silently passing everything.

The refresh refuses to write a result implausibly smaller than the current one, because a rate-limit
page served with a 200 would otherwise disable the rule quietly. See [Lists](tools/lists.md).

## `role_accounts`

```php
'role_accounts' => ['careers', 'jobs'],
```

Added to the RFC 2142 mailboxes the package already knows. "Which addresses are not a person" has a
house style — some organisations want `careers@` rejected on a signup form and some do not.

## `dns`

```php
'dns' => [
    'store' => env('LARANAIL_EMAIL_DNS_STORE'),
    'positive_ttl' => (int) env('LARANAIL_EMAIL_DNS_POSITIVE_TTL', 86400),
    'negative_ttl' => (int) env('LARANAIL_EMAIL_DNS_NEGATIVE_TTL', 300),
],
```

**The TTLs are asymmetric on purpose.** A domain that resolves today will almost certainly resolve
tomorrow, so a positive is cached for a day. A negative may only mean a transient outage, and caching
that for a day turns a blip into a day of rejected signups — so it is five minutes. See
[Resolver](tools/resolver.md).

> On the `array` cache driver, a TTL means "for this request". That is the driver's nature rather
> than this setting's, but it is worth knowing before wondering why every request re-queries.

## `scanning`

Defaults for `Mail::find()`, which locates addresses inside prose rather than parsing a field.

```php
'scanning' => [
    'leniency' => env('LARANAIL_EMAIL_SCAN_LENIENCY', 'VALID'),
    'limit' => (int) env('LARANAIL_EMAIL_SCAN_LIMIT', PHP_INT_MAX),
],
```

`VALID` is the default: the domain must have a dot and a plausible top-level label, which is what
stops `ssh deploy@web-01` in a pasted command from being read as a contact. `POSSIBLE` finds anything
address-shaped and is right for redaction, where a false positive costs a blacked-out word and a
false negative leaks a real address. `DELIVERABLE` additionally requires an MX record and is the only
rung that touches the network. See [Scanner](tools/scanner.md).

## `api`

Off, and turning it on is the whole security decision. Nothing is registered until `enabled` is
`true`, so an install that never touches this adds no routes.

```php
'api' => [
    'enabled' => env('LARANAIL_EMAIL_API_ENABLED', false),
    'prefix' => env('LARANAIL_EMAIL_API_PREFIX', 'api/laranail/email'),
    'middleware' => ['api'],
    'throttle' => env('LARANAIL_EMAIL_API_THROTTLE', '60,1'),
    'max_batch' => (int) env('LARANAIL_EMAIL_API_MAX_BATCH', 1000),
    'allow_reachability' => env('LARANAIL_EMAIL_API_ALLOW_REACHABILITY', true),
],
```

> **`middleware` is not authentication.** `api` is Laravel's stock group — throttling and route-model
> binding. Enabling the API with that alone publishes an endpoint that will parse anything anyone
> sends it. Put `auth:sanctum`, a token middleware or an IP allow-list in the list first.

The throttle is **appended** to whatever you configure, so your authentication runs first — rejecting
an unauthenticated request should not spend its rate-limit budget. A throttle already in the list is
left alone, because two limiters give a rate that is neither of the numbers written down.

`allow_reachability: false` refuses MX lookups outright rather than relying on callers not to ask.
Full reference: [HTTP API](tools/api.md).

---

[← Docs index](../README.md#documentation)

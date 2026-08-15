# HTTP API

Four endpoints — analyse, batch, audit, scan — for the callers that are not PHP. **Off by default.**

```php
// config/laranail/email.php
'api' => [
    'enabled' => true,
    'middleware' => ['api', 'auth:sanctum'],
],
```

In this section: [Turning it on safely](#turning-it-on-safely) · [Endpoints](#endpoints) ·
[Errors](#errors) · [Limits](#limits) · [Route names](#route-names)

## Turning it on safely

The package registers **no routes at all** until config says so. A package that publishes endpoints
by being installed changes an application's attack surface as a side effect of `composer require`,
and the person who notices is rarely the person who ran it.

> **`middleware` is not authentication.** The default is `['api']` — Laravel's stock group, which is
> throttling and route-model binding. Enabling the API with that alone publishes an endpoint that
> will parse anything anyone sends it. Put `auth:sanctum`, a token middleware or an IP allow-list in
> the list before exposing it.

**The throttle is automatic**, appended to whatever middleware is configured unless the list already
contains one. Appended rather than prepended, so your authentication runs first — rejecting an
unauthenticated request should not consume its rate-limit budget. A throttle you wrote down yourself
is left alone; adding a second silently would give the route two buckets and an effective rate that
is neither of the numbers anyone wrote.

| Key | Default | |
|---|---|---|
| `api.enabled` | `false` | Nothing is registered until this is `true` |
| `api.prefix` | `api/laranail/email` | URI prefix; route names are fixed |
| `api.middleware` | `['api']` | **Read the warning above** |
| `api.throttle` | `'60,1'` | Appended unless already present; `null` opts out |
| `api.max_batch` | `1000` | Enforced with a 422, never a truncation |
| `api.allow_reachability` | `true` | Whether a request may ask for MX lookups |

`allow_reachability: false` refuses the DNS check outright. It is the only thing here that leaves the
process, so an application behind a strict egress policy can say no rather than relying on callers
not to ask.

## Endpoints

### `POST {prefix}/analyze`

```json
{ "email": "Alice+News@Example.COM", "check_reachability": false, "keep_subaddress": false }
```

```json
{
  "data": {
    "input": "Alice+News@Example.COM",
    "parseable": true, "usable": true,
    "address": "Alice+News@example.com",
    "canonical": "alice@example.com",
    "local_part": "Alice+News", "domain": "example.com",
    "mailbox": "Alice", "tag": "News",
    "disposable": false, "role_account": false,
    "reachable": null,
    "problems": []
  }
}
```

An unparseable address is **200 with `parseable: false`**, not an error. The request was well formed
and the answer is simply no.

`mailbox` keeps its case and `domain` does not, because RFC 5321 §2.4 makes local parts
case-sensitive — ignoring case is a provider's policy, not the standard. `canonical` is where that
decision gets made deliberately.

`reachable` is **`null` when the check did not run**. A `false` would read as "we looked and there is
no mail exchanger", which is a much stronger claim than "we did not look".

### `POST {prefix}/batch`

```json
{ "emails": ["alice@example.com", "Alice@Example.com", "junk"] }
```

Returns one object per input under `data` — each carrying `index`, `duplicate_of` and its own
`problems` — and the whole report under `meta`.

With `"check_reachability": true` the MX lookups are grouped **per domain**, so ten thousand
addresses at one provider cost one lookup. That is what makes the option usable over a batch at all.

### `POST {prefix}/audit`

The same pass with the per-row payload dropped. For "is this list worth importing", which is a
question about the list:

```json
{
  "data": {
    "summary": { "total": 4200, "usable": 3910, "duplicates": 61, "domains": 812,
                 "checked_reachability": true },
    "domains": { "gmail.com": 1902, "example.co.ke": 411 },
    "problems": { "role_account": 180, "disposable": 74, "unreachable": 36 },
    "duplicates": { "alice@example.com": [7, 391] },
    "unusable": [ { "index": 19, "input": "info@temp.test",
                    "problems": ["role_account", "disposable"] } ]
  }
}
```

The unusable rows keep their `index` even though the rest of the payload is gone, because a count
alone is not something anyone can act on.

### `POST {prefix}/scan`

Free text in, the addresses it contains out, with byte offsets so a caller can highlight or redact
the right occurrence rather than the first one.

```json
{ "text": "Write to alice@example.com.", "leniency": "VALID" }
```

```json
{
  "data": [ { "raw": "alice@example.com", "offset": 9, "end": 26,
              "address": "alice@example.com", "canonical": "alice@example.com",
              "domain": "example.com" } ],
  "meta": { "count": 1 }
}
```

`leniency` is one of `POSSIBLE`, `VALID`, `DELIVERABLE` — see [Scanner](scanner.md) for which to
pick. A `DELIVERABLE` request is answered at `VALID` when `allow_reachability` is off: the caller
still gets addresses, just a weaker claim, which beats erroring on a request that was reasonable.

## Errors

Validation failures are Laravel's standard 422:

```json
{ "message": "The emails field must not have more than 1000 items.",
  "errors": { "emails": ["…"] } }
```

There is no `FormRequest` behind them. That class lives in `illuminate/foundation`, which is not
published as a standalone package — depending on it would mean depending on `laravel/framework`
entirely. `Validator::make()` throws the same exception and Laravel renders the same body.

## Limits

`max_batch` is **enforced, not applied**. A caller that sent 5,000 and got 1,000 back has a bug it
cannot see, so over-sized batches are a 422 naming the field.

Per-value caps: 320 characters for an address, the RFC 5321 maximum; 100,000 for scanned text.

## Route names

Every route is named `laranail.email.api.{analyze,batch,audit,scan}`, so `route()` resolves them and
the prefix is written down in exactly one place.

```php
route('laranail.email.api.audit');
```

---

[← Docs index](../../README.md#documentation)

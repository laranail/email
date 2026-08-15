# Architecture

One sentence: the rules live in `laranail/validation`, the data and the IO live here, and
contracts join them.

## Why the rules are not here

A rule that checks whether an address is disposable is a *pure* function of a list. Putting it
here would mean an application that wants that rule has to take a dependency that also carries
a DNS resolver, an HTTP fetch command and tens of thousands of lines of domain data.

So `laranail/validation` declares three contracts and ships small fallbacks behind them:

```
Contracts\Email\DisposableDomainList
Contracts\Email\RoleAccountList
Contracts\Email\DnsResolver
```

Its rules — `NotDisposableEmail`, `NotRoleEmail`, `Network\DeliverableEmail` — depend on the
contracts, never on this package. `laranail/validation` contains no reference to a
`laranail/email` class, and that direction is enforced by the dependency graph: this package
requires validation, not the reverse.

## Why the bindings are asymmetric, and why that is not an accident

`laranail/validation` binds its fallbacks with **`singletonIf`**. This package binds its
implementations with **`singleton`**.

That asymmetry is the whole mechanism. Service provider order is not something a consuming
application controls, so both orders have to produce the same result:

| Boot order | What happens |
|---|---|
| validation, then email | validation binds the fallback; email overwrites it with `singleton` |
| email, then validation | email binds; validation's `singletonIf` sees the binding and leaves it |

Using `singletonIf` in both would make the outcome depend on order — the second provider would
lose, and which one that is varies by installation. Using `singleton` in both would mean
validation clobbered this package half the time.

The test suite boots **validation first** on purpose: that is the order that breaks if this
package ever switches to `singletonIf`, so it is the order worth exercising.

## Why a failed DNS lookup passes

`DnsResolver::hasMailExchanger()` returns `true` when it cannot get an answer, and that is
specified in the contract rather than left to each implementation.

An unreachable, rate-limited or slow resolver is not the same as a domain that cannot receive
mail, and no implementation can tell the two apart. Returning `false` on a transient outage
rejects every signup for its duration. Deliverability is a quality filter, not a security
boundary, and the cost of a false negative is far higher than the cost of letting one bad
address through to a bounce.

`checkdnsrr` cannot distinguish "no" from "no answer" — it returns `false` for both — so the
resolver probes a name that must exist before concluding anything.

## Why the refresh refuses small results

A successful HTTP response can still be a truncated file, a rate-limit page or an HTML error
served with a 200. Writing that over a working list would silently disable the rule, so the
command refuses any result implausibly smaller than the current one and reports why.

The same reasoning applies at read time: an empty refreshed file falls back to the bundled
snapshot rather than being treated as an authoritative empty list.

## Why batch is a separate object, not a loop

`Mail::audit()` could have been `array_map(Mail::of(...), $rows)` and callers could have counted the
results. Three things make it worth its own pass.

**Reachability is resolved per domain.** Ten thousand addresses at one provider is one MX lookup here
and ten thousand in the loop anyone would write. A list is overwhelmingly a handful of consumer
providers plus a long tail, so this is the ordinary shape of the data rather than a corner case — and
it is the difference between batch reachability being a feature and being a way to hammer a resolver.

**The verdict on the list is not the verdict on the rows.** `domains()` is the first thing worth
looking at on an imported list, and it does not exist at all unless something holds the whole pass.
Because it comes from the same pass as the rows, the two cannot disagree.

**Duplicates need a stable survivor.** `duplicateOf` points at the *first* row producing a canonical
address, so de-duplicating is a filter and the winner is the original signup rather than whichever
row a hash map happened to keep.

The cost is memory: `audit()` is O(n). `each()` gives that back as a generator, at the price of the
report **and** of per-domain reachability — grouping by domain means seeing the whole list, which is
precisely what a generator exists to avoid. It is not offered there rather than offered badly.

## Why the HTTP API is off by default

A package that publishes endpoints by being installed changes an application's attack surface as a
side effect of `composer require`, and the person who notices is rarely the person who ran it. So
`ApiRoutes::register()` returns immediately unless config says otherwise, and it lives outside the
service provider so the decision is readable on its own.

Three smaller choices follow from the same reasoning:

- **The throttle is appended, not prepended.** Authentication runs first, so rejecting an
  unauthenticated request does not consume its rate-limit budget.
- **The batch cap is enforced with a 422, never a truncation.** A caller that sent 5,000 and got
  1,000 back has a bug it cannot see.
- **`allow_reachability` can refuse the DNS check outright**, because it is the one thing here that
  leaves the process and an application behind a strict egress policy should not have to trust
  callers not to ask.

There is no `FormRequest` anywhere in it. That class lives in `illuminate/foundation`, which is not
published as a standalone Composer package, so using it would mean requiring `laravel/framework` in
full. `Validator::make()` throws the same exception and Laravel renders the same body.

## Why the config publishes to a nested path

The package reads `config('laranail.email.*')` and publishes to `config/laranail/email.php`. Those
have to agree, because Laravel keys config by **filename**: a flat `config/laranail-email.php` loads
under `laranail-email`, and a package reading the dotted key never sees it. Nothing errors — the
packaged defaults keep answering, and the person who edited the file has no way to tell.

That is exactly what shipped, and the fix was to stop hand-rolling it. `laranail/package-tools` owns
both halves: it publishes the namespaced key to the nested path *and* merges that published file back
over the defaults at boot, because Laravel does not auto-load nested config directories.

---

[← Docs index](../README.md#documentation)

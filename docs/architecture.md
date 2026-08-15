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

---

[← Docs index](../README.md#documentation)

# Changelog

All notable changes to `laranail/email` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- `Mail::audit()` — judges a whole list in one pass, and answers two questions from it: what each
  address is, and what is wrong with the list. `domains()` is the first thing worth looking at on an
  import: one domain holding most of the rows is either a corporate export or a leak, and the two
  need very different handling.
- **Reachability over a batch is resolved per domain, not per address.** Ten thousand addresses at
  one provider cost one MX lookup rather than ten thousand. A list is overwhelmingly a handful of
  consumer providers plus a long tail, so this is the ordinary shape of the data — and it is the
  difference between batch reachability being usable and being a way to hammer a resolver. It stays
  off by default; it is the only thing here that leaves the process.
- `Mail::each()`, the same pass streamed, for a file larger than memory. Duplicate detection survives
  it; the report and the per-domain grouping do not, and are not offered there rather than offered
  badly.
- Duplicates are detected on the **canonical** address, so `alice@`, `Alice@` and `alice+news@` are
  one mailbox. `duplicateOf` points at the first row that produced it, so de-duplicating is a filter
  and the survivor is deterministically the original signup rather than the re-registration.
- An opt-in HTTP API: `analyze`, `batch` and `audit`. **Off by default** — a package that publishes
  endpoints by being installed changes an application's attack surface as a side effect of
  `composer require`. When enabled it is throttled automatically, the throttle is appended *after*
  your authentication so a rejected request does not spend its rate-limit budget, an over-sized batch
  is a 422 rather than a silent truncation, and `allow_reachability` can refuse the DNS check
  outright for an application behind a strict egress policy.
- `docs/getting-started.md`, `docs/configuration.md` and `docs/release.md`, which the package was
  missing.


- `Mail::of()` — a fluent builder over the value object, shaped deliberately like
  `laranail/phone`'s so a developer who has learned one knows the other. `canonical()` names the
  deduplication policy rather than leaving it implicit, `keepSubaddress()` opts out of it, and
  `problems()` returns everything wrong with an address at once instead of one failure per round
  trip. The MX check inside it stays off by default — it is the only thing here that leaves the
  process.
- `Mail::unique()`, which deduplicates an iterable of addresses by canonical form. What an import
  actually needs; `array_unique()` keeps all four spellings of one mailbox.
- `Email`, an address value object. Splits on the **last** `@` so a quoted local part like
  `"a@b"@example.com` parses correctly, lowercases the domain but **not** the local part
  (RFC 5321 §2.4 makes local parts case-sensitive; ignoring case is a provider policy, not the
  standard), and exposes `mailbox()`, `tag()`, `canonical()`, `isAt()` and `equals()`.
- `MaintainedDisposableDomainList` and `MaintainedRoleAccountList`, implementing
  `laranail/validation`'s contracts. Matching walks up the labels, so an entry for a domain
  catches its subdomains. A refreshed list is preferred over the bundled snapshot — unless it
  is empty, which means a truncated download rather than a world with no disposable domains.
- `CachedDnsResolver`, the production deliverability resolver: asymmetric TTLs (a positive is
  durable, a negative may be a transient outage), a configurable cache store, and a
  short-circuit for the providers most addresses belong to.
- `laranail::email.refresh-lists`, which fetches the CC0 upstream list. It refuses to write a
  result implausibly smaller than the current one, because a rate-limit page served with a 200
  would otherwise silently disable the rule.
- `Testing\FakeDnsResolver`, so a test never makes a DNS query.
- A CC0 snapshot of 8,201 disposable domains as the fallback.

### Fixed

- **The published config was never read.** The package reads `config('laranail.email.*')` and
  published its file to `config/laranail-email.php`. Laravel keys config by *filename*, so the
  published copy loaded under `laranail-email` and nothing looked there — the packaged defaults kept
  answering, and the person who edited the file had no way to tell. The provider now uses
  `laranail/package-tools`, which publishes the namespaced key to `config/laranail/email.php` *and*
  merges that file back over the defaults at boot, because Laravel does not auto-load nested config.
- `illuminate/cache` added to `require`. `CachedDnsResolver` has always used the `Cache` facade, so
  the dependency was real and merely undeclared — it worked in an application and would have failed a
  resolve of the package on its own.

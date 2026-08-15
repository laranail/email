# Changelog

All notable changes to `laranail/email` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

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

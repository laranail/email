# Contributing

Issues and pull requests are welcome. This package ships as a Composer library, so every
public class, method, and config key is part of a surface downstream consumers depend on —
changes are weighed against that.

## Getting set up

```bash
composer install
composer test
```

There is no host application to click through. The package is exercised through
[Orchestra Testbench](https://packages.tools/testbench), which boots a Laravel kernel at test
time. Run Artisan through `vendor/bin/testbench`, never `php artisan`.

## The checks

Run all three before opening a pull request. CI runs the same ones.

| Command | What it checks |
|---|---|
| `composer test` | The Pest suite |
| `composer phpstan` | Static analysis at level `max`, plus type coverage |
| `vendor/bin/pint --test` | Code style (reports without rewriting) |

`composer qa` runs Rector, Pint, and PHPStan together. `vendor/bin/pint` (no flag) applies
style fixes rather than reporting them.

## Supported versions

PHP `^8.4.1 || ^8.5` on Laravel `^13.0`. The test matrix runs PHP 8.4 / 8.5 on Ubuntu and
Windows, under both `prefer-lowest` and `prefer-stable`. Code must work on the floor of that
range, not just the version installed locally — `prefer-lowest` exists to catch exactly that.

The floor comes from the laranail foundation packages, which are `^8.4.1` and Laravel 13 only.
PHP 8.3 and Laravel 12 were supported before that adoption and are not now.

## Tests are the specification

- Every behavioural change needs a test. A bug fix starts with a test that reproduces the bug.
- Prefer a test over a throwaway verification script.
- Parity tests (`*ParityTest.php`) assert this package produces the same verdict as native
  Laravel validation for the same input. When touching rule compilation, extend them.

## Coding conventions

- Match the surrounding code. Check sibling files before introducing a new pattern.
- Descriptive names over short ones (`resolvePublishDestination`, not `resolve`).
- Prefer `final` classes; mark anything not intended for extension `@internal`.
- No new runtime dependency without discussion — consumers inherit every one.
- Comments explain *why*, not *what*.

## Pull requests

- One concern per pull request.
- Subject line in the imperative mood, 72 characters or fewer.
- Say how the change was verified, and paste the output that shows it.
- Breaking changes to the public surface need a version bump and a migration note in the
  release body. Once the package has released versions to migrate between, that note also
  belongs in an `UPGRADING.md`.

`CHANGELOG.md` is maintained by CI from the release body — do not hand-edit it in a pull
request.

## Reporting a security issue

Do not open a public issue. Follow [SECURITY.md](SECURITY.md).

---

## AI tooling

This package uses [boost-core](https://github.com/sandermuller/boost-core)
for AI-assisted development, via two dev dependencies:

- [`sandermuller/boost-skills`](https://github.com/sandermuller/boost-skills) — the shared skill + guideline catalog.
- [`sandermuller/package-boost-laravel`](https://github.com/sandermuller/package-boost-laravel) — the Laravel-package role engine (pulls `boost-core` + `package-boost-php`).

The engine syncs `.ai/` sources plus allowlisted vendor skills/guidelines
into the directories each AI tool expects (`.claude/`, `.github/`,
`.agents/`, `CLAUDE.md`, `AGENTS.md`). Configuration lives in
`.config/boost.php` (allowed vendors, agents, tags). There is no MCP
server — this package
does not depend on `laravel/boost`.

### Setup

```bash
composer install
```

`.config/boost.php` is committed, so no install step is needed. To
reconfigure agents/vendors interactively, run `vendor/bin/boost install`.

### Authoring skills and guidelines

Edit sources under `.ai/` — never edit the generated agent directories:

```
.ai/
├── guidelines/   # merged into CLAUDE.md, AGENTS.md, Copilot instructions
└── skills/       # synced to .claude/skills/, .github/skills/, .agents/skills/
```

Vendor skills/guidelines are enabled through `.config/boost.php`'s
`withAllowedVendors([...])` + `withTags([...])`. Inspect what resolves
and from where with `vendor/bin/boost where`.

> **Shipped product vs dev tooling.** `resources/boost/skills/` holds the
> `laranail-validation` skills this package *ships to its own consumers* — a
> separate axis from `.ai/` (what we consume while developing). Do not
> confuse the two; adoption tooling never touches `resources/boost/`.

### Sync after edits or dependency updates

```bash
composer sync-ai
```

Equivalent to `vendor/bin/boost sync`. Regenerates skills and guidelines
for Claude Code, Codex, and Copilot from `.ai/` + allowlisted vendors.

The generated agent directories (`.claude/skills/`, `.github/skills/`,
`.agents/skills/`, `.claude/commands/`, `.github/prompts/`) are
**gitignored** — they are regenerated, not committed. Commit only the
`.ai/` sources and `.config/boost.php`.

### Verify

```bash
vendor/bin/boost sync --check   # report drift without writing (non-zero exit on drift)
vendor/bin/boost validate       # validate boost.php against vendor schemas
vendor/bin/boost doctor         # diagnose config, allowlist, drift
```

# Release

Versioning, tagging, and how the changelog gets into the GitHub release.

## Versioning

Semantic versioning. While the package is pre-1.0, the minor is the breaking-change segment: `0.2.0`
may break what `0.1.x` did.

Consumers constrain on `^0.1`.

## What counts as breaking

- Removing or renaming a public method, or narrowing a parameter type.
- Changing what `canonical()` returns for an address that already round-tripped. That value is
  routinely stored in a unique-indexed column, so a change to it is a data migration for every
  consumer, not a behaviour tweak.
- Changing a config key, or the meaning of an existing one — including the published path.
- Raising the PHP or Laravel floor.
- Changing the JSON the HTTP API returns, once a field is documented. The wire format is a contract
  even though the controller is not.

**A list refresh is not breaking**, even though it changes answers: a domain that was not disposable
last week may be this week. That is the list tracking reality, and freezing it would be worse. The
same goes for a DNS answer.

## Cutting a release

1. Update `CHANGELOG.md` under a new `## [X.Y.Z]` heading, following Keep a Changelog.
2. Commit.
3. Tag `vX.Y.Z` and push the tag.

```bash
git tag v0.2.0
git push origin v0.2.0
```

CI extracts that version's changelog section and passes it as the GitHub release body. A release
without a real description is incomplete — auto-generated notes or a "see CHANGELOG" stub do not
count.

## The `branch-alias`

`composer.json` carries a `branch-alias` mapping `dev-main` to the current line, so a path or dev
checkout still satisfies a `^0.1` constraint:

```json
"extra": {
    "branch-alias": { "dev-main": "0.1.x-dev" }
}
```

Bump it when the line moves. The alias key must match the actual branch name — `dev-main` on a repo
sitting on `master` resolves as `dev-master`, which has no alias and satisfies nothing.

## Never leave an unreachable tag

A tag that is not an ancestor of `main` is worse than no tag. Composer's VCS driver reads tags, not
reachability, so it will happily offer an orphaned `v0.4.0` as the newest version while `main` is
0.1-line code — and anyone writing `^0.4` silently gets abandoned history.

If history is rewritten, delete and re-cut every affected tag.

## Moving in step with `laranail/validation`

This package implements contracts that live in `laranail/validation`. A change to one of those
contracts is a coordinated release: the contract package first, then this one, then the constraint
here. Releasing this one against an unreleased contract produces a version nobody can install.

## Distribution

`laranail/*` packages resolve through git VCS repositories rather than Packagist. Consumers add:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/laranail/email" },
    { "type": "vcs", "url": "https://github.com/laranail/validation" }
]
```

Composer ignores a dependency's own `repositories`, so a root package must declare the whole
transitive `laranail/*` closure, not just its direct dependencies — here that means `validation`,
`package-tools` and `console` as well as this package.

## No lock file

`composer.lock` is not tracked. In a library it records a resolution consumers never use, and it goes
stale invisibly because CI resolves fresh.

---

[← Docs index](../README.md#documentation)

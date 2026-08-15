# Credits

## Data

| Data | Source | Licence |
|---|---|---|
| `resources/data/disposable-domains.txt` | [disposable-email-domains/disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains) | CC0 1.0 (public domain) |

The role-account list is not taken from anywhere: it is the RFC 2142 §§3–5 mailboxes, which are
a specification rather than someone's compilation, plus the departmental addresses in common
use.

## Standards

| Behaviour | Reference |
|---|---|
| Local parts are case-sensitive; domains are not | RFC 5321 §2.4 |
| A domain with an address record and no MX still takes delivery | RFC 5321 §5.1 |
| The mailbox names every domain is expected to run | RFC 2142 |

## Not used

`laravel-validation-rules/offensive` and any LGPL-licensed list are deliberately absent: LGPL
code cannot be copied into an MIT package. Where a word or domain list has no recorded licence,
it is not bundled either — see `laranail/validation`'s `Contracts\TermList` for the pattern
used instead.

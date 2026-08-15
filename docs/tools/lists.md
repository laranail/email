# Lists

Two lists, with different lifecycles.

## Disposable domains

`MaintainedDisposableDomainList` answers whether a domain belongs to a throwaway-mailbox
provider, resolving in this order:

1. The refreshed file, written by `laranail::email.refresh-lists`, if it exists and is
   non-empty.
2. The CC0 snapshot bundled with this package.

Matching **walks up the labels**, so an entry for `throwaway.test` catches
`mail.throwaway.test` and `a.b.throwaway.test`. Providers hand out subdomains freely and
listing every one is not possible. It stops before the public suffix, so an entry can never
turn `com` into a match for everything.

A domain that merely *ends with* a listed one is not a subdomain of it: `notmailinator.com` is
only caught because it is genuinely on the list, not because `mailinator.com` is.

### Refreshing

```bash
php artisan laranail::email.refresh-lists            # fetch and write
php artisan laranail::email.refresh-lists --dry-run  # report without writing
```

The source is CC0 and configurable at `laranail.email.lists.source`. The destination is
`laranail.email.lists.path`, defaulting to `storage/app/laranail-email`.

The command **refuses to write a result implausibly smaller than the current list**. A
rate-limit page or a truncated download served with a 200 would otherwise replace a working
list with nothing, and the rule would pass everything while appearing to work.

## Role accounts

`MaintainedRoleAccountList` answers whether a local part addresses a function rather than a
person — `info@`, `sales@`, `postmaster@`.

Unlike the disposable list this does **not** change, so it is a constant rather than a
refreshable file. RFC 2142 has been stable since 1997; a network fetch for it would be
ceremony.

Extend it from config, because "which addresses are not a person" has a house style:

```php
// config/laranail-email.php
'role_accounts' => ['bookings', 'enquiries'],
```

The rule strips a plus tag before checking, so `info+signup@` is still the `info` mailbox.

---

[← Docs index](../../README.md#documentation)

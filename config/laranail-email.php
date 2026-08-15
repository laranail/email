<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | List storage
    |--------------------------------------------------------------------------
    |
    | Where `laranail::email.refresh-lists` writes the refreshed
    | disposable-domain list. Until it has run — or if this path is not
    | writable — the snapshot shipped with the package is used instead, so the
    | rules keep working rather than silently passing everything.
    |
    */

    'lists' => [
        'path' => env('LARANAIL_EMAIL_LISTS_PATH'),

        /*
         | Where the refreshed list is fetched from. The default is the
         | disposable-email-domains project, which is CC0 (public domain) and
         | is the source the bundled snapshot was taken from.
         */
        'source' => env(
            'LARANAIL_EMAIL_LISTS_SOURCE',
            'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf',
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Role accounts
    |--------------------------------------------------------------------------
    |
    | Added to the RFC 2142 mailboxes the package already knows. "Which
    | addresses are not a person" has a house style — some organisations want
    | `careers@` rejected on a signup form and some do not.
    |
    */

    'role_accounts' => [],

    /*
    |--------------------------------------------------------------------------
    | Deliverability lookups
    |--------------------------------------------------------------------------
    |
    | The TTLs are deliberately asymmetric. A domain that resolves today will
    | almost certainly resolve tomorrow, so a positive is cached for a day. A
    | negative may only mean a transient outage, and caching that for a day
    | turns a blip into a day of rejected signups — so it is cached for five
    | minutes.
    |
    */

    'dns' => [
        'store' => env('LARANAIL_EMAIL_DNS_STORE'),
        'positive_ttl' => (int) env('LARANAIL_EMAIL_DNS_POSITIVE_TTL', 86400),
        'negative_ttl' => (int) env('LARANAIL_EMAIL_DNS_NEGATIVE_TTL', 300),
    ],

];

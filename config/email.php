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

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    |
    | Analyse, batch and audit addresses over HTTP, for the callers that are
    | not PHP: a Node service, a data pipeline, an internal admin tool.
    |
    | OFF BY DEFAULT, and turning it on is the whole security decision.
    |
    | > `middleware` below is NOT authentication. `api` is Laravel's stock
    | > group — throttling and route-model binding — and adding these routes
    | > with it alone publishes an endpoint that will parse anything anyone
    | > sends it. Put `auth:sanctum`, a token middleware, or an IP allow-list
    | > in this list before enabling it on anything reachable.
    |
    | A `throttle` is appended automatically unless the middleware list already
    | contains one, so removing the rate limit also takes an explicit act. Set
    | `throttle` to null to opt out.
    |
    */
    'api' => [
        'enabled' => env('LARANAIL_EMAIL_API_ENABLED', false),

        // Mounted under this URI prefix; route names are derived from it.
        'prefix' => env('LARANAIL_EMAIL_API_PREFIX', 'api/laranail/email'),

        // Read the warning above before changing this.
        'middleware' => ['api'],

        // Laravel's `throttle:{maxAttempts},{decayMinutes}` argument, or null.
        'throttle' => env('LARANAIL_EMAIL_API_THROTTLE', '60,1'),

        // The most addresses one batch or audit request may carry. Exceeding it
        // is a 422 naming the field — never a silent truncation, because a
        // caller that sent 5,000 and got 1,000 back has a bug it cannot see.
        'max_batch' => (int) env('LARANAIL_EMAIL_API_MAX_BATCH', 1000),

        // Whether a request may ask for MX lookups. This is the only thing here
        // that leaves the process, so an application behind a strict egress
        // policy can refuse it outright rather than relying on callers not to
        // ask.
        'allow_reachability' => env('LARANAIL_EMAIL_API_ALLOW_REACHABILITY', true),
    ],

];

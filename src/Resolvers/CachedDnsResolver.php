<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Resolvers;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;
use Throwable;

/**
 * The production MX resolver, replacing the one `laranail/validation` bundles.
 *
 * It differs from that fallback in three ways, each of which needs a
 * configured cache and a real deployment to be worth having:
 *
 * 1. **Negative results are cached for less time than positive ones.** A
 *    domain that resolves today will almost certainly resolve tomorrow; a
 *    domain that failed may have failed because of a transient outage, and
 *    caching that for an hour turns a blip into an hour of rejected signups.
 * 2. **The cache store is configurable**, so deliverability lookups can be
 *    pointed at a shared store rather than an array store that is empty on
 *    every request.
 * 3. **A permanent allow-list short-circuits the lookup.** The handful of
 *    providers most addresses belong to do not need checking, and skipping
 *    them removes most of the traffic.
 *
 * The uncertainty rule is inherited and non-negotiable: a lookup that cannot
 * complete returns TRUE. See the contract.
 */
final readonly class CachedDnsResolver implements DnsResolver
{
    private const string CACHE_PREFIX = 'laranail-email:mx:';

    /** Providers that will not stop accepting mail while a request is in flight. */
    private const array ALWAYS_DELIVERABLE = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com',
        'yahoo.com', 'icloud.com', 'me.com', 'proton.me', 'protonmail.com',
    ];

    public function __construct(
        private ?Repository $cache = null,
        private ?int $positiveTtl = null,
        private ?int $negativeTtl = null,
    ) {}

    public function hasMailExchanger(string $domain): bool
    {
        $domain = self::normalise($domain);

        if ($domain === '') {
            return false;
        }

        if (in_array($domain, self::ALWAYS_DELIVERABLE, true)) {
            return true;
        }

        $store = $this->cache ?? self::defaultStore();
        $key = self::CACHE_PREFIX.$domain;

        if ($store instanceof Repository) {
            $cached = $store->get($key);

            if (is_bool($cached)) {
                return $cached;
            }
        }

        $result = self::lookup($domain);

        // Asymmetric TTLs: a positive is durable, a negative may be an outage.
        $store?->put($key, $result, $result
            ? $this->positiveTtl ?? self::ttl('positive', 86400)
            : $this->negativeTtl ?? self::ttl('negative', 300));

        return $result;
    }

    private static function lookup(string $domain): bool
    {
        if (@checkdnsrr($domain, 'MX')) {
            return true;
        }

        // RFC 5321 §5.1: an address record with no MX still takes delivery.
        if (@checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'AAAA')) {
            return true;
        }

        // checkdnsrr cannot distinguish "no" from "no answer", so confirm the
        // resolver is reachable before treating this as a negative. If it is
        // not, the contract says answer true.
        return ! @checkdnsrr('a.root-servers.net', 'A');
    }

    private static function normalise(string $domain): string
    {
        $domain = mb_strtolower(trim($domain, ". \t\n\r\0\x0B"));

        if ($domain === '' || mb_check_encoding($domain, 'ASCII')) {
            return $domain;
        }

        if (! function_exists('idn_to_ascii')) {
            return $domain;
        }

        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? $ascii : $domain;
    }

    private static function defaultStore(): ?Repository
    {
        $configured = config('laranail.email.dns.store');

        try {
            return Cache::store(is_string($configured) && $configured !== '' ? $configured : null);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ttl(string $which, int $default): int
    {
        $value = config("laranail.email.dns.{$which}_ttl");

        return is_int($value) && $value > 0 ? $value : $default;
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Lists;

use Simtabi\Laranail\Validation\Contracts\Email\DisposableDomainList;

/**
 * The maintained disposable-domain list, replacing the small snapshot that
 * `laranail/validation` bundles as a fallback.
 *
 * Two files, in order:
 *
 * 1. A refreshed copy under the configured storage path, written by
 *    `laranail::email.refresh-lists`.
 * 2. The snapshot shipped with this package.
 *
 * The fallback matters: a deployment that has never run the refresh command,
 * or one whose storage is read-only, still gets a working list rather than a
 * rule that silently passes everything. A list that quietly becomes empty is
 * worse than no list, because the application still believes it is filtering.
 *
 * Matching walks up the domain, so `mail.throwaway.test` is caught by an entry
 * for `throwaway.test`. Providers hand out subdomains freely and listing every
 * one is not possible.
 */
final class MaintainedDisposableDomainList implements DisposableDomainList
{
    /** @var array<string, true>|null */
    private ?array $domains = null;

    public function __construct(
        private readonly ?string $refreshedPath = null,
        private readonly ?string $bundledPath = null,
    ) {}

    public static function defaultRefreshedPath(): string
    {
        $configured = config('laranail.email.lists.path');

        $directory = is_string($configured) && $configured !== ''
            ? $configured
            : storage_path('app/laranail-email');

        return $directory.'/disposable-domains.txt';
    }

    public static function bundledPath(): string
    {
        return dirname(__DIR__, 2).'/resources/data/disposable-domains.txt';
    }

    public function contains(string $domain): bool
    {
        $domain = mb_strtolower(trim($domain, ". \t\n\r\0\x0B"));

        if ($domain === '') {
            return false;
        }

        $domains = $this->domains();

        if ($domains === []) {
            return false;
        }

        // Walk up the labels: an entry for the registrable domain should catch
        // every subdomain beneath it.
        $labels = explode('.', $domain);

        for ($i = 0; $i < count($labels) - 1; $i++) {
            if (isset($domains[implode('.', array_slice($labels, $i))])) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return count($this->domains());
    }

    /** @return array<string, true> */
    private function domains(): array
    {
        if ($this->domains !== null) {
            return $this->domains;
        }

        $refreshed = $this->refreshedPath ?? self::defaultRefreshedPath();

        if (is_file($refreshed)) {
            $loaded = DomainFile::load($refreshed);

            // An empty refreshed file means a truncated download, not a world
            // with no disposable domains. Fall through to the snapshot.
            if ($loaded !== []) {
                return $this->domains = $loaded;
            }
        }

        return $this->domains = DomainFile::load($this->bundledPath ?? self::bundledPath());
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Lists;

/**
 * Loads a newline-delimited list file into an O(1) lookup.
 *
 * The list is kept as a FILE rather than a PHP array, and read lazily. A
 * disposable-domain list runs to tens of thousands of entries; as a PHP array
 * that is parsed and held in memory on every request whether or not any
 * address is validated. As a file it costs nothing until something asks.
 *
 * Flipped to a hash on load: `in_array` over 40,000 entries is a linear scan
 * per address, and the whole point of the list is to answer quickly.
 *
 * @internal
 */
final class DomainFile
{
    /** @return array<string, true> */
    public static function load(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $entries = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = mb_strtolower(trim($line));

            // `#` starts a comment so the file can carry its own provenance —
            // where it came from, under what licence, when it was taken.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $entries[$line] = true;
        }

        return $entries;
    }
}

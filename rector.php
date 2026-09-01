<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\BooleanOr\RepeatedOrEqualToInArrayRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

/**
 * Pinned to the **php84** set, matching this package's `^8.4.1 || ^8.5` floor — the same choice
 * `laranail/phone` makes, and for the same reason: the 8.5 set would rewrite code into syntax that
 * parses on the newer CI leg and fails on the older one, which is the quietest way to break a
 * supported version.
 *
 * `config/` is deliberately not a path. That file is a literal array by design — no closures, so
 * `config:cache` works — and there is nothing there for a code-quality rule to improve.
 */
return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withSkip([
        __DIR__.'/vendor',

        // Rewrites `$x === null` into `! $x instanceof \Fully\Qualified\Name`, inlining an FQCN into
        // a condition that was already clear. For a `?self` parameter the null check *is* the
        // intent, and the instanceof form reads as a type guard that is not what the code means.
        FlipTypeControlToUseExclusiveTypeRector::class,

        // Collapses `$p === false || $p === 0 || $p === $len - 1` into an `in_array` over a
        // three-element array. Shorter, and worse on both counts that matter: the three comparisons
        // are three distinct failure cases a reader can name, and the rewrite allocates an array on
        // every call to a parser that runs once per address in a batch of ten thousand.
        RepeatedOrEqualToInArrayRector::class,
    ])
    ->withPhpSets(php84: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
    ])
    ->withImportNames(removeUnusedImports: true);

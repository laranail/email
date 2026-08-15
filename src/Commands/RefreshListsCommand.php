<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;
use Simtabi\Laranail\Email\Lists\DomainFile;
use Simtabi\Laranail\Email\Lists\MaintainedDisposableDomainList;
use Throwable;

/**
 * Refresh the disposable-domain list from its upstream source.
 *
 *     php artisan laranail::email.refresh-lists
 *
 * Schedule it weekly. The list moves — providers appear and disappear — and a
 * snapshot frozen at install time decays quietly: it keeps working, it just
 * stops catching anything new, which is the kind of failure nobody notices.
 */
final class RefreshListsCommand extends Command
{
    use SupportsNamespacedNames;

    /**
     * The `::` separator is what the laranail convention requires. Symfony's
     * `Command::validateName()` rejects the empty segment it creates, so the
     * name is written past that validator by SupportsNamespacedNames — and the
     * command still dispatches, because Symfony resolves an exact name before
     * its `:`-splitting namespace lookup.
     */
    protected $signature = 'laranail::email.refresh-lists {--dry-run : Report what would change without writing}';

    protected $description = 'Refresh the disposable email domain list from its upstream source';

    public function handle(): int
    {
        $source = config('laranail.email.lists.source');

        if (! is_string($source) || $source === '') {
            $this->components->error('No source configured at laranail.email.lists.source.');

            return self::FAILURE;
        }

        $this->components->info("Fetching {$source}");

        try {
            $response = Http::timeout(30)->get($source);
        } catch (Throwable $e) {
            $this->components->error('Fetch failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->components->error("Fetch failed with HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $domains = $this->parse($response->body());

        // A successful response can still be a truncated file, a rate-limit
        // page, or an HTML error served with a 200. Writing that over a
        // working list would silently disable the rule, so refuse anything
        // implausibly small rather than trust the status code.
        $existing = count(DomainFile::load(MaintainedDisposableDomainList::bundledPath()));
        $floor = max(100, (int) ($existing * 0.5));

        if (count($domains) < $floor) {
            $this->components->error(sprintf(
                'Refusing to write: got %d domains, expected at least %d. The source may be truncated.',
                count($domains),
                $floor,
            ));

            return self::FAILURE;
        }

        $path = MaintainedDisposableDomainList::defaultRefreshedPath();

        if ($this->option('dry-run')) {
            $this->components->info(sprintf('Would write %d domains to %s.', count($domains), $path));

            return self::SUCCESS;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->components->error("Could not create {$directory}.");

            return self::FAILURE;
        }

        $header = implode("\n", [
            '# Disposable email domains.',
            '# Source: '.$source,
            '# Licence: CC0 1.0 (public domain).',
            '# Written by laranail::email.refresh-lists — do not edit by hand.',
            '',
        ]);

        if (file_put_contents($path, $header.implode("\n", $domains)."\n") === false) {
            $this->components->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $this->components->info(sprintf('Wrote %d domains to %s.', count($domains), $path));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function parse(string $body): array
    {
        $domains = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = mb_strtolower(trim($line));

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Only things shaped like a domain. An HTML error page served with
            // a 200 would otherwise become thousands of "domains".
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $line) === 1) {
                $domains[$line] = true;
            }
        }

        return array_keys($domains);
    }
}

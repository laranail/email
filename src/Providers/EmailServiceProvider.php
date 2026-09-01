<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Providers;

use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Email\Commands\RefreshListsCommand;
use Simtabi\Laranail\Email\EmailBatch;
use Simtabi\Laranail\Email\EmailManager;
use Simtabi\Laranail\Email\EmailScanner;
use Simtabi\Laranail\Email\Enums\ScanLeniency;
use Simtabi\Laranail\Email\Http\ApiRoutes;
use Simtabi\Laranail\Email\Http\EmailPresenter;
use Simtabi\Laranail\Email\Lists\MaintainedDisposableDomainList;
use Simtabi\Laranail\Email\Lists\MaintainedRoleAccountList;
use Simtabi\Laranail\Email\Resolvers\CachedDnsResolver;
use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;
use Simtabi\Laranail\Validation\Contracts\Email\DisposableDomainList;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\RoleAccountList;

/**
 * Binds this package's implementations over the fallbacks that
 * `laranail/validation` ships.
 */
class EmailServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laranail/email')
            ->hasConfigFile()
            ->hasTranslations();
    }

    /**
     * `singleton`, NOT `singletonIf`, and the asymmetry with validation is the
     * whole mechanism.
     *
     * validation binds its bundled fallbacks with `singletonIf`, so whichever
     * provider boots second still produces the right result: if validation
     * boots first, this one replaces its snapshot; if this one boots first,
     * validation sees the binding and leaves it alone. Using `singletonIf`
     * here as well would make the outcome depend on provider order, which is
     * not something a consuming application controls.
     */
    public function registeringPackage(): void
    {
        $this->app->singleton(DisposableDomainList::class, MaintainedDisposableDomainList::class);
        $this->app->singleton(RoleAccountList::class, MaintainedRoleAccountList::class);
        $this->app->singleton(DnsResolver::class, CachedDnsResolver::class);

        $this->app->singleton(EmailBatch::class, fn (): EmailBatch => new EmailBatch(
            disposable: $this->app->make(DisposableDomainList::class),
            roleAccounts: $this->app->make(RoleAccountList::class),
            dns: $this->app->make(DnsResolver::class),
        ));

        $this->app->singleton(EmailScanner::class, fn (): EmailScanner => new EmailScanner(
            dns: $this->app->make(DnsResolver::class),
            leniency: ScanLeniency::tryFrom($this->string(config('laranail.email.scanning.leniency')) ?? 'VALID')
                ?? ScanLeniency::Valid,
            limit: $this->int(config('laranail.email.scanning.limit')) ?? PHP_INT_MAX,
        ));

        // Bound even when the API is off: the wire format is useful to an application writing its
        // own controller, and it costs nothing until something resolves it.
        $this->app->singleton(EmailPresenter::class, fn (): EmailPresenter => new EmailPresenter);

        // The facade's accessor. A front over the four above rather than a fifth implementation,
        // so `Email` stays a value object that parses and nothing else.
        $this->app->singleton(EmailManager::class, fn (): EmailManager => new EmailManager(
            disposable: $this->app->make(DisposableDomainList::class),
            roleAccounts: $this->app->make(RoleAccountList::class),
            dns: $this->app->make(DnsResolver::class),
            batch: $this->app->make(EmailBatch::class),
            scanner: $this->app->make(EmailScanner::class),
        ));
    }

    public function bootingPackage(): void
    {
        ApiRoutes::register($this->app->make(Repository::class));

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([RefreshListsCommand::class]);
    }

    /**
     * A config value as a string, or null.
     *
     * `config()` returns `mixed` and a cast would paper over that rather than answer it: a leniency
     * set to an array or a stray `true` in `.env` should fall back to the default, not become the
     * string `"1"` and silently widen what the scanner accepts.
     */
    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

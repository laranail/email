<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email;

use Simtabi\Laranail\Email\Commands\RefreshListsCommand;
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
        $this->mergeConfigFrom($this->configPath(), 'laranail.email');

        $this->app->singleton(DisposableDomainList::class, MaintainedDisposableDomainList::class);
        $this->app->singleton(RoleAccountList::class, MaintainedRoleAccountList::class);
        $this->app->singleton(DnsResolver::class, CachedDnsResolver::class);
    }

    public function bootingPackage(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([RefreshListsCommand::class]);

        $this->publishes(
            [$this->configPath() => config_path('laranail-email.php')],
            $this->package->getNamespacedPublishTag('config'),
        );
    }

    private function configPath(): string
    {
        return dirname(__DIR__).'/config/laranail-email.php';
    }
}

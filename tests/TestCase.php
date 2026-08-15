<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Simtabi\Laranail\Email\Providers\EmailServiceProvider;
use Simtabi\Laranail\Validation\ValidationServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        // Validation first, deliberately: it binds its fallbacks with
        // singletonIf, so booting it first is the order that would break if
        // this package used singletonIf too. The suite should exercise the
        // fragile order, not the forgiving one.
        return [
            ValidationServiceProvider::class,
            EmailServiceProvider::class,
        ];
    }
}

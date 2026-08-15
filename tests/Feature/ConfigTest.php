<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Simtabi\Laranail\Email\Http\ApiRoutes;
use Simtabi\Laranail\Email\Providers\EmailServiceProvider;

/*
|--------------------------------------------------------------------------
| Config wiring
|--------------------------------------------------------------------------
|
| A regression guard for a defect that shipped and was invisible: the package
| read `config('laranail.email.*')` and published its file to
| `config/laranail-email.php`. Laravel keys config by FILENAME, so a published,
| edited file loaded under `laranail-email` and nothing ever read it — the
| packaged defaults kept answering, and the person who edited the file had no
| way to tell.
|
| The fix was to stop hand-rolling it: laranail/package-tools publishes a
| namespaced key to the nested path AND merges that published file back over
| the defaults at boot, because Laravel does not auto-load nested config.
|
*/

it('reads its configuration under the vendor-namespaced key', function (): void {
    expect(config('laranail.email'))->toBeArray()
        ->and(config('laranail.email.dns.positive_ttl'))->toBeInt()
        ->and(config('laranail.email.api.enabled'))->toBeFalse();
});

it('publishes to the path that loads back under that key', function (): void {
    // config/laranail/email.php → config('laranail.email.*'). A flat
    // config/laranail-email.php would load under 'laranail-email' instead, which is the whole bug.
    $paths = ServiceProvider::pathsToPublish(EmailServiceProvider::class, 'laranail::email-config');

    expect($paths)->not->toBeEmpty()
        ->and(array_values($paths))->toContain(config_path('laranail/email.php'));
});

it('registers API routes to controller actions, not closures, so route:cache works', function (): void {
    // A route bound to a closure cannot be serialised, and `route:cache` fails on it — at deploy.
    config()->set('laranail.email.api.enabled', true);

    ApiRoutes::register(config());

    $routes = array_filter(
        app('router')->getRoutes()->getRoutes(),
        static fn ($route): bool => str_starts_with((string) $route->getName(), ApiRoutes::NAME_PREFIX),
    );

    expect($routes)->toHaveCount(3);

    foreach ($routes as $route) {
        expect($route->getActionName())->not->toBe('Closure');
    }
});

it('leaves no closures in the config file, so config:cache still works', function (): void {
    // A closure in config is a deploy-time failure, not a development one.
    $config = require dirname(__DIR__, 2).'/config/email.php';

    $walk = function (array $values) use (&$walk): void {
        foreach ($values as $value) {
            if (is_array($value)) {
                $walk($value);

                continue;
            }

            expect($value)->not->toBeInstanceOf(Closure::class);
        }
    };

    $walk($config);
});

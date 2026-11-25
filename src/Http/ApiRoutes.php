<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Simtabi\Laranail\Email\Http\Controllers\EmailApiController;

/**
 * Registers the HTTP API, if it has been turned on.
 *
 * Kept out of the service provider because the decision it encodes is worth reading on its own: this
 * package adds **no routes at all** unless an application asks for them. A package that publishes
 * endpoints by installing it is a package that changes an application's attack surface as a side
 * effect of `composer require`, and the person who notices is rarely the person who ran it.
 *
 * The shape is deliberately identical to `laranail/phone`'s, down to the config keys, so the two can
 * be enabled and secured the same way.
 */
final class ApiRoutes
{
    public const string NAME_PREFIX = 'laranail.email.api.';

    public static function register(Repository $config): void
    {
        if ($config->get('laranail.email.api.enabled', false) !== true) {
            return;
        }

        $configured = $config->get('laranail.email.api.prefix', 'api/laranail/email');
        $prefix = trim(is_string($configured) ? $configured : 'api/laranail/email', '/');

        Route::prefix($prefix)
            ->middleware(self::middleware($config))
            ->name(self::NAME_PREFIX)
            ->group(static function (): void {
                Route::post('/analyze', [EmailApiController::class, 'analyze'])->name('analyze');
                Route::post('/batch', [EmailApiController::class, 'batch'])->name('batch');
                Route::post('/audit', [EmailApiController::class, 'audit'])->name('audit');
                Route::post('/scan', [EmailApiController::class, 'scan'])->name('scan');
            });
    }

    /**
     * The configured middleware, with a throttle appended unless one is already there.
     *
     * Appended rather than prepended so an application's own authentication runs first — rejecting
     * an unauthenticated request should not consume its rate-limit budget, or an unauthenticated
     * caller could exhaust the bucket for everyone sharing the limiter's key.
     *
     * An existing `throttle` is left alone. Silently adding a second limiter would give the route
     * two buckets with different keys and an effective rate that is neither of the two numbers
     * anyone wrote down.
     *
     * @return list<string>
     */
    public static function middleware(Repository $config): array
    {
        /** @var list<string> $middleware */
        $middleware = array_values((array) $config->get('laranail.email.api.middleware', ['api']));

        $throttle = $config->get('laranail.email.api.throttle');

        if (! is_string($throttle) || $throttle === '') {
            return $middleware;
        }

        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'throttle')) {
                return $middleware;
            }
        }

        $middleware[] = "throttle:{$throttle}";

        return $middleware;
    }
}

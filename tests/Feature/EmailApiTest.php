<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Simtabi\Laranail\Email\Http\ApiRoutes;

/*
|--------------------------------------------------------------------------
| The HTTP API
|--------------------------------------------------------------------------
|
| Two things are being guarded here and only one of them is the JSON. The
| other is that installing this package adds nothing to an application's
| attack surface: no routes exist until config says so, and when they do
| they are rate limited whether or not anyone remembered to ask.
|
*/

it('registers no routes at all until an application asks for them', function (): void {
    $names = array_keys(Route::getRoutes()->getRoutesByName());

    expect(array_filter($names, static fn (string $n): bool => str_starts_with($n, ApiRoutes::NAME_PREFIX)))->toBe([]);
});

describe('with the API enabled', function (): void {
    beforeEach(function (): void {
        config()->set('laranail.email.api.enabled', true);

        ApiRoutes::register(config());
    });

    it('answers everything known about one address', function (): void {
        $this->postJson('api/laranail/email/analyze', ['email' => 'Alice+News@Example.COM'])
            ->assertOk()
            ->assertJsonPath('data.parseable', true)
            ->assertJsonPath('data.canonical', 'alice@example.com')
            ->assertJsonPath('data.domain', 'example.com')
            // Preserved, not lowercased: RFC 5321 §2.4 makes local parts case-sensitive, and
            // ignoring case is a provider's policy rather than the standard.
            ->assertJsonPath('data.mailbox', 'Alice')
            ->assertJsonPath('data.tag', 'News')
            ->assertJsonPath('data.problems', []);
    });

    it('hands an unparseable address back with a problem rather than an error', function (): void {
        $this->postJson('api/laranail/email/analyze', ['email' => 'not an address'])
            ->assertOk()
            ->assertJsonPath('data.parseable', false)
            ->assertJsonPath('data.usable', false)
            ->assertJsonPath('data.problems', ['unparseable']);
    });

    it('keeps the subaddress when asked to', function (): void {
        $this->postJson('api/laranail/email/analyze', ['email' => 'alice+news@example.com', 'keep_subaddress' => true])
            ->assertOk()
            ->assertJsonPath('data.canonical', 'alice+news@example.com');
    });

    it('reports reachability as unknown unless it was checked', function (): void {
        // Null, not false — "we did not look" is a different claim from "there is no mail exchanger".
        $this->postJson('api/laranail/email/analyze', ['email' => 'alice@example.com'])
            ->assertOk()
            ->assertJsonPath('data.reachable', null);
    });

    it('refuses an MX lookup when the application has switched it off', function (): void {
        // For an application behind a strict egress policy: refusing outright beats relying on
        // callers not to ask.
        config()->set('laranail.email.api.allow_reachability', false);

        $this->postJson('api/laranail/email/analyze', ['email' => 'alice@example.com', 'check_reachability' => true])
            ->assertOk()
            ->assertJsonPath('data.reachable', null);
    });

    it('returns one result per input plus the report', function (): void {
        $this->postJson('api/laranail/email/batch', [
            'emails' => ['alice@example.com', 'Alice@Example.com', 'junk'],
        ])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.duplicate_of', 0)
            ->assertJsonPath('meta.summary.total', 3)
            ->assertJsonPath('meta.summary.duplicates', 1)
            ->assertJsonPath('meta.duplicates', ['alice@example.com' => [0, 1]]);
    });

    it('drops the per-row payload for an audit but keeps the failures addressable', function (): void {
        $response = $this->postJson('api/laranail/email/audit', [
            'emails' => ['alice@example.com', 'junk'],
        ])->assertOk();

        $response->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.unusable.0.index', 1)
            ->assertJsonPath('data.unusable.0.problems', ['unparseable']);

        expect($response->json('data'))->not->toHaveKey('entries');
    });

    it('rejects an over-sized batch instead of silently truncating it', function (): void {
        config()->set('laranail.email.api.max_batch', 2);

        $this->postJson('api/laranail/email/batch', ['emails' => ['a@example.com', 'b@example.com', 'c@example.com']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('emails');
    });

    it('finds addresses in free text with their offsets', function (): void {
        $this->postJson('api/laranail/email/scan', [
            'text' => 'Write to alice@example.com or bob@example.org.',
        ])
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('data.0.address', 'alice@example.com')
            ->assertJsonPath('data.0.offset', 9)
            // The full stop is sentence punctuation, not part of the domain.
            ->assertJsonPath('data.1.address', 'bob@example.org');
    });

    it('rejects a leniency it does not know', function (): void {
        $this->postJson('api/laranail/email/scan', ['text' => 'hello', 'leniency' => 'WHATEVER'])
            ->assertJsonValidationErrors('leniency');
    });

    it('refuses to escalate a scan to DNS when reachability is switched off', function (): void {
        // The request asked for the network and the application said no, so it is answered at the
        // rung below rather than errored — the caller still gets addresses, just a weaker claim.
        config()->set('laranail.email.api.allow_reachability', false);

        $this->postJson('api/laranail/email/scan', [
            'text' => 'alice@example.com',
            'leniency' => 'DELIVERABLE',
        ])->assertOk()->assertJsonPath('meta.count', 1);
    });

    it('rejects an empty or malformed payload', function (): void {
        $this->postJson('api/laranail/email/batch', ['emails' => []])->assertStatus(422);
        $this->postJson('api/laranail/email/batch', ['emails' => 'not an array'])->assertStatus(422);
        $this->postJson('api/laranail/email/analyze', [])->assertJsonValidationErrors('email');
    });
});

describe('middleware', function (): void {
    it('adds a throttle to whatever the application configured', function (): void {
        config()->set('laranail.email.api.middleware', ['api']);
        config()->set('laranail.email.api.throttle', '60,1');

        expect(ApiRoutes::middleware(config()))->toBe(['api', 'throttle:60,1']);
    });

    it('appends rather than prepends, so authentication runs first', function (): void {
        config()->set('laranail.email.api.middleware', ['api', 'auth:sanctum']);

        expect(ApiRoutes::middleware(config()))->toBe(['api', 'auth:sanctum', 'throttle:60,1']);
    });

    it('leaves a throttle the application wrote down alone', function (): void {
        config()->set('laranail.email.api.middleware', ['api', 'throttle:10,1']);

        expect(ApiRoutes::middleware(config()))->toBe(['api', 'throttle:10,1']);
    });

    it('takes null as a deliberate opt-out', function (): void {
        config()->set('laranail.email.api.middleware', ['api']);
        config()->set('laranail.email.api.throttle');

        expect(ApiRoutes::middleware(config()))->toBe(['api']);
    });

    it('actually attaches the middleware to the registered routes', function (): void {
        config()->set('laranail.email.api.enabled', true);
        config()->set('laranail.email.api.middleware', ['api', 'auth:sanctum']);

        ApiRoutes::register(config());
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName(ApiRoutes::NAME_PREFIX.'batch');

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('auth:sanctum', 'throttle:60,1');
    });
});

it('honours a custom prefix', function (): void {
    config()->set('laranail.email.api.enabled', true);
    config()->set('laranail.email.api.prefix', 'internal/email');

    ApiRoutes::register(config());

    $this->postJson('internal/email/analyze', ['email' => 'alice@example.com'])
        ->assertOk()
        ->assertJsonPath('data.canonical', 'alice@example.com');
});

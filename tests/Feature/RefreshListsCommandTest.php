<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Console\Kernel;
use Simtabi\Laranail\Email\Lists\MaintainedDisposableDomainList;

beforeEach(function (): void {
    config()->set('laranail.email.lists.path', sys_get_temp_dir() . '/laranail-email-test');
    @unlink(MaintainedDisposableDomainList::defaultRefreshedPath());
});

it('writes a fetched list', function (): void {
    $domains = array_map(static fn (int $i): string => "spam{$i}.test", range(1, 9000));
    Http::fake(['*' => Http::response(implode("\n", $domains))]);

    $this->artisan('laranail::email.refresh-lists')->assertSuccessful();

    $written = MaintainedDisposableDomainList::defaultRefreshedPath();

    expect(file_exists($written))->toBeTrue()
        ->and(file_get_contents($written))->toContain('spam1.test')
        // The file records where it came from and under what licence.
        ->and(file_get_contents($written))->toContain('CC0 1.0');
});

it('refuses to write a result implausibly smaller than the current list', function (): void {
    // A rate-limit page or a truncated download served with a 200 would
    // otherwise replace a working list with nothing, and the rule would pass
    // everything while appearing to work.
    Http::fake(['*' => Http::response("only-one.test\n")]);

    $this->artisan('laranail::email.refresh-lists')->assertFailed();

    expect(file_exists(MaintainedDisposableDomainList::defaultRefreshedPath()))->toBeFalse();
});

it('ignores anything that is not shaped like a domain', function (): void {
    // An HTML error page served with a 200 would otherwise become thousands
    // of "domains".
    $body = "<html><body>Rate limited</body></html>\n" . implode("\n", array_map(
        static fn (int $i): string => "spam{$i}.test",
        range(1, 9000),
    ));
    Http::fake(['*' => Http::response($body)]);

    $this->artisan('laranail::email.refresh-lists')->assertSuccessful();

    expect(file_get_contents(MaintainedDisposableDomainList::defaultRefreshedPath()))
        ->not->toContain('<html>');
});

it('reports without writing on a dry run', function (): void {
    $domains = array_map(static fn (int $i): string => "spam{$i}.test", range(1, 9000));
    Http::fake(['*' => Http::response(implode("\n", $domains))]);

    $this->artisan('laranail::email.refresh-lists', ['--dry-run' => true])->assertSuccessful();

    expect(file_exists(MaintainedDisposableDomainList::defaultRefreshedPath()))->toBeFalse();
});

it('fails cleanly when the fetch does not succeed', function (): void {
    Http::fake(['*' => Http::response('', 503)]);

    $this->artisan('laranail::email.refresh-lists')->assertFailed();
});

it('registers under the laranail:: namespaced name', function (): void {
    // The org convention: laranail::<slug>.<command>. Symfony dispatches it
    // because an exact name resolves before the `:`-splitting lookup.
    expect(array_keys($this->app[Kernel::class]->all()))
        ->toContain('laranail::email.refresh-lists');
});

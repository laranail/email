<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Email\EmailBatch;
use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;
use Simtabi\Laranail\Email\Jobs\AuditEmailColumn;
use Simtabi\Laranail\Email\Support\EmailAuditReport;
use Simtabi\Laranail\Email\Testing\FakeDnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\DisposableDomainList;
use Simtabi\Laranail\Validation\Contracts\Email\RoleAccountList;

/*
|--------------------------------------------------------------------------
| The streaming report, and the job that uses it
|--------------------------------------------------------------------------
|
| The property that matters: the same addresses in produce the same report
| whether they were held or streamed. If those two ever disagree, two
| dashboards built on the same data show different figures and nobody can
| tell which is right.
|
*/

it('produces the same report streamed as held', function (): void {
    $rows = ['alice@example.com', 'Alice@Example.com', 'support@example.com', 'junk'];

    expect(EmailAddress::report($rows)->toArray())->toBe(EmailAddress::audit($rows)->report());
});

it('never holds the entries', function (): void {
    // Warmed first, and the warming is the point: the disposable-domain snapshot is 8,201 entries
    // loaded lazily on the first judgement, so measuring across it would attribute a fixed one-off
    // cost to the accumulator and the number would grow with nothing.
    EmailAddress::report(['warm@example.com']);

    $rows = (function (): Generator {
        for ($i = 0; $i < 10_000; $i++) {
            yield ['alice@example.com', 'bob@example.com', 'carol@example.org'][$i % 3];
        }
    })();

    $before = memory_get_usage();
    $report = EmailAddress::report($rows);
    $growth = memory_get_usage() - $before;

    expect($report->summary())->toMatchArray(['total' => 10_000, 'duplicates' => 9_997])
        ->and($report->unique())->toHaveCount(3)
        ->and($growth)->toBeLessThan(1_000_000);
});

it('says plainly that it did not check reachability', function (): void {
    // An absent `unreachable` count means "nobody looked", not "nothing was unreachable". A report
    // that cannot tell a reader which is misleading, so it is declared rather than inferred.
    expect(EmailAddress::report(['alice@example.com'])->summary()['checked_reachability'])->toBeFalse();
});

it('keeps the held audit reachability flag when it delegates', function (): void {
    // The delegation must not flatten what the holding path knows and the streaming one cannot.
    $dns = FakeDnsResolver::everything();

    $batch = new EmailBatch(
        disposable: app(DisposableDomainList::class),
        roleAccounts: app(RoleAccountList::class),
        dns: $dns,
    );

    $report = $batch->audit(['alice@example.com'], checkReachability: true)->report();

    expect($report['summary']['checked_reachability'])->toBeTrue();
});

it('merges two reports, and finds duplicates neither chunk could see alone', function (): void {
    $first = EmailAddress::report(['alice@example.com', 'bob@example.com']);
    $second = EmailAddress::report(['ALICE@example.com']);

    $first->merge($second);

    expect($first->summary())->toMatchArray(['total' => 3, 'duplicates' => 1, 'distinct' => 2])
        ->and($first->domains())->toBe(['example.com' => 3]);
});

it('samples duplicate row indexes but counts them exactly', function (): void {
    $rows = array_fill(0, EmailAuditReport::SAMPLE_LIMIT + 50, 'alice@example.com');

    $report = EmailAddress::report($rows);

    expect($report->duplicateGroups()['alice@example.com'])->toHaveCount(EmailAuditReport::SAMPLE_LIMIT)
        ->and($report->duplicateCounts()['alice@example.com'])->toBe(EmailAuditReport::SAMPLE_LIMIT + 50)
        ->and($report->summary()['duplicates'])->toBe(EmailAuditReport::SAMPLE_LIMIT + 49);
});

it('counts nothing as nothing', function (): void {
    expect((new EmailAuditReport)->summary())->toMatchArray(['total' => 0, 'usable' => 0, 'unusable' => 0]);
});

describe('the queued job', function (): void {
    beforeEach(function (): void {
        Schema::create('subscribers', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->boolean('active')->default(true);
        });
    });

    it('audits a whole column and caches the report', function (): void {
        Subscriber::insert([
            ['email' => 'alice@example.com', 'active' => true],
            ['email' => 'Alice@Example.com', 'active' => true],
            ['email' => 'junk', 'active' => true],
            ['email' => null, 'active' => true],
        ]);

        new AuditEmailColumn(Subscriber::class, 'email', key: 'subscribers')->handle(app(EmailBatch::class));

        $report = Cache::get('laranail.email.audit.subscribers');

        expect($report['summary'])->toMatchArray(['total' => 4, 'duplicates' => 1, 'unparseable' => 2])
            ->and($report['duplicates'])->toBe(['alice@example.com' => [0, 1]]);
    });

    it('numbers rows across chunk boundaries, not within them', function (): void {
        Subscriber::insert([
            ['email' => 'alice@example.com', 'active' => true],
            ['email' => 'bob@example.com', 'active' => true],
            ['email' => 'ALICE@example.com', 'active' => true],
        ]);

        new AuditEmailColumn(Subscriber::class, 'email', key: 'chunked', chunk: 1)->handle(app(EmailBatch::class));

        expect(Cache::get('laranail.email.audit.chunked')['duplicates'])
            ->toBe(['alice@example.com' => [0, 2]]);
    });

    it('keeps subaddressed mailboxes apart when told to', function (): void {
        Subscriber::insert([
            ['email' => 'alice@example.com', 'active' => true],
            ['email' => 'alice+news@example.com', 'active' => true],
        ]);

        new AuditEmailColumn(Subscriber::class, 'email', keepSubaddress: true, key: 'tagged')
            ->handle(app(EmailBatch::class));

        expect(Cache::get('laranail.email.audit.tagged')['summary']['duplicates'])->toBe(0);
    });

    it('honours a named scope', function (): void {
        Subscriber::insert([
            ['email' => 'alice@example.com', 'active' => true],
            ['email' => 'bob@example.com', 'active' => false],
        ]);

        new AuditEmailColumn(Subscriber::class, 'email', key: 'scoped', scope: 'active')
            ->handle(app(EmailBatch::class));

        expect(Cache::get('laranail.email.audit.scoped')['summary']['total'])->toBe(1);
    });

    it('publishes progress as it goes', function (): void {
        Subscriber::insert(array_map(
            static fn (int $i): array => ['email' => "user{$i}@example.com", 'active' => true],
            range(1, 5),
        ));

        new AuditEmailColumn(Subscriber::class, 'email', key: 'progress', chunk: 2)->handle(app(EmailBatch::class));

        expect(Cache::get('laranail.email.audit.progress.progress'))->toBe(5);
    });

    it('refuses a class that is not a model, by name', function (): void {
        expect(fn () => new AuditEmailColumn(stdClass::class, 'email')->handle(app(EmailBatch::class)))
            ->toThrow(InvalidArgumentException::class, 'is not an Eloquent model');
    });

    it('refuses a scope that does not return a builder', function (): void {
        expect(fn () => new AuditEmailColumn(Subscriber::class, 'email', scope: 'notAScope')->handle(app(EmailBatch::class)))
            ->toThrow(InvalidArgumentException::class, 'did not return a query builder');
    });
});

class Subscriber extends Model
{
    public $timestamps = false;

    protected $table = 'subscribers';

    protected $guarded = [];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Deliberately wrong: a scope that does not return a builder cannot be chained, and the job
     * should say so rather than quietly auditing the whole table.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotAScope(Builder $query): string
    {
        return 'not a builder';
    }
}

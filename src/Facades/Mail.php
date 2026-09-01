<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Facades;

use Generator;
use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\Email\Email;
use Simtabi\Laranail\Email\EmailBatch;
use Simtabi\Laranail\Email\EmailBuilder;
use Simtabi\Laranail\Email\EmailManager;
use Simtabi\Laranail\Email\EmailScanner;
use Simtabi\Laranail\Email\Enums\ScanLeniency;
use Simtabi\Laranail\Email\Support\EmailAudit;
use Simtabi\Laranail\Email\Support\EmailAuditEntry;
use Simtabi\Laranail\Email\Support\EmailAuditReport;
use Simtabi\Laranail\Email\Support\EmailMatch;
use Simtabi\Laranail\Validation\Contracts\Email\DisposableDomainList;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\RoleAccountList;

/**
 * The package's entry point, over {@see EmailManager}.
 *
 * Deliberately **not** registered as a global alias, and named `Mail` only inside this namespace —
 * Laravel's own `Mail` facade owns that name globally, and claiming it would replace the mailer with
 * something that cannot send. Import it, and alias at the call site if the collision bothers you:
 *
 * ```php
 * use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;
 *
 * EmailAddress::of('Alice+News@Example.COM')->canonical();   // 'alice@example.com'
 * ```
 *
 * @method static EmailBuilder of(?string $address)
 * @method static Email|null parse(?string $address)
 * @method static list<string> unique(iterable<mixed, string|null> $addresses, bool $keepSubaddress = false)
 * @method static EmailAudit audit(iterable<mixed, string|null> $addresses, bool $checkReachability = false, bool $keepSubaddress = false)
 * @method static Generator<int, EmailAuditEntry> each(iterable<mixed, string|null> $addresses, bool $keepSubaddress = false)
 * @method static EmailAuditReport report(iterable<mixed, string|null> $addresses, bool $keepSubaddress = false)
 * @method static EmailBatch batch()
 * @method static list<EmailMatch> find(?string $text, ?ScanLeniency $leniency = null)
 * @method static string|null replaceIn(?string $text, callable $replace, ?ScanLeniency $leniency = null)
 * @method static string|null redact(?string $text, string $maskChar = '•', ?ScanLeniency $leniency = null)
 * @method static EmailScanner scanner()
 * @method static DisposableDomainList disposableList()
 * @method static RoleAccountList roleAccountList()
 * @method static DnsResolver dns()
 *
 * @see EmailManager
 */
final class Mail extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EmailManager::class;
    }
}

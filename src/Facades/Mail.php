<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Facades;

use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\Email\Email;
use Simtabi\Laranail\Email\EmailBuilder;
use Simtabi\Laranail\Email\EmailManager;
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
 * @method static list<string> unique(iterable<string|null> $addresses)
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

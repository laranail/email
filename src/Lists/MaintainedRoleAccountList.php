<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Lists;

use Simtabi\Laranail\Validation\Contracts\Email\RoleAccountList;

/**
 * Local parts that address a function rather than a person.
 *
 * The RFC 2142 mailboxes every domain is expected to run, plus the common
 * departmental ones. Unlike the disposable list this does not change, so it
 * is a constant rather than a refreshable file — there is no upstream to
 * track, and a network fetch for a list that has been stable since 1997 would
 * be ceremony.
 *
 * Additional entries come from config, because "which addresses are not a
 * person" has a house style: some organisations want `careers@` rejected on a
 * signup form and some do not.
 */
final class MaintainedRoleAccountList implements RoleAccountList
{
    /** RFC 2142 §§3–5, then the departmental mailboxes in common use. */
    private const array LOCAL_PARTS = [
        // RFC 2142 — business
        'info', 'marketing', 'sales', 'support',
        // RFC 2142 — network operations
        'abuse', 'noc', 'security',
        // RFC 2142 — protocol-specific
        'postmaster', 'hostmaster', 'usenet', 'news', 'webmaster', 'www',
        'uucp', 'ftp',
        // Common departmental
        'admin', 'administrator', 'accounts', 'accounting', 'billing',
        'careers', 'compliance', 'contact', 'enquiries', 'enquiry', 'feedback',
        'finance', 'help', 'helpdesk', 'hello', 'hr', 'jobs', 'legal', 'mail',
        'mailer-daemon', 'media', 'newsletter', 'no-reply', 'noreply', 'office',
        'orders', 'press', 'privacy', 'recruitment', 'root', 'service',
        'subscribe', 'team', 'unsubscribe',
    ];

    /** @var array<string, true>|null */
    private ?array $lookup = null;

    /** @param  list<string>  $additional */
    public function __construct(private readonly array $additional = []) {}

    public function contains(string $localPart): bool
    {
        $localPart = mb_strtolower(trim($localPart));

        return $localPart !== '' && isset($this->lookup()[$localPart]);
    }

    public function count(): int
    {
        return count($this->lookup());
    }

    /** @return array<string, true> */
    private function lookup(): array
    {
        if ($this->lookup !== null) {
            return $this->lookup;
        }

        $configured = config('laranail.email.role_accounts', []);
        $extra = is_array($configured) ? $configured : [];

        $all = [...self::LOCAL_PARTS, ...$this->additional, ...$extra];

        $normalised = [];

        foreach ($all as $localPart) {
            if (is_string($localPart) && trim($localPart) !== '') {
                $normalised[mb_strtolower(trim($localPart))] = true;
            }
        }

        return $this->lookup = $normalised;
    }
}

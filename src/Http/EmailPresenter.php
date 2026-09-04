<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Http;

use Simtabi\Laranail\Email\EmailBuilder;
use Simtabi\Laranail\Email\Support\EmailAuditEntry;

/**
 * Turns the package's objects into the JSON the API returns.
 *
 * Separate from the controller because the wire format is a contract and the controller is not: a
 * caller written against `canonical` and `problems` should keep working across a refactor of how the
 * routes are wired, and the only way to make that true is to have exactly one place that decides
 * what an address looks like on the wire.
 *
 * Deliberately the same shape as `laranail/phone`'s presenter — `input`, the parsed parts, the
 * verdict, the problems — so a client that consumes both is written once.
 */
final readonly class EmailPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function address(EmailBuilder $email, bool $checkReachability = false): array
    {
        $problems = $email->problems($checkReachability);

        return [
            'input'        => (string) $email,
            'parseable'    => $email->isParseable(),
            'usable'       => $problems === [],
            'address'      => $email->value() === null ? null : (string) $email->value(),
            'canonical'    => $email->canonical(),
            'local_part'   => $email->localPart(),
            'domain'       => $email->domain(),
            'mailbox'      => $email->mailbox(),
            'tag'          => $email->tag(),
            'disposable'   => $email->isDisposable(),
            'role_account' => $email->isRoleAccount(),
            // Null rather than false when the check did not run. A false here would read as "we
            // looked and there is no mail exchanger", which is a different and much stronger claim.
            'reachable' => $checkReachability ? $email->isReachable() : null,
            'problems'  => $problems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(EmailAuditEntry $entry): array
    {
        return $entry->toArray();
    }
}

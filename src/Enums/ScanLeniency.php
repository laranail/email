<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Enums;

use Simtabi\Laranail\Email\EmailScanner;

/**
 * How readily {@see EmailScanner} accepts a candidate.
 *
 * The trade is between missing addresses and inventing them, and the right point depends entirely on
 * what the text is for. Redacting a document wants everything that might be an address, because a
 * false positive costs a blacked-out word and a false negative leaks a real one. Harvesting contacts
 * from a scraped page wants the opposite.
 *
 * String-backed so the value survives a config file, a JSON response and a log line legibly.
 */
enum ScanLeniency: string
{
    /**
     * Anything shaped like `local@domain`.
     *
     * Finds `root@localhost` and `deploy@web-01`, which are real addresses on a private network and
     * noise in a marketing list. Use it for redaction, where over-matching is the safe direction.
     */
    case Possible = 'POSSIBLE';

    /**
     * The domain has a dot and a plausible top-level label.
     *
     * The default. Excludes single-label hosts, which is what separates prose about people from
     * prose about servers — `ssh deploy@web-01` stops being a contact.
     */
    case Valid = 'VALID';

    /**
     * As `Valid`, and the domain has a mail exchanger.
     *
     * The only level that touches the network, and the strongest claim available without sending:
     * somebody accepts mail for that domain. It still says nothing about whether the mailbox exists.
     * Lookups are cached and resolved once per distinct domain per scan.
     */
    case Deliverable = 'DELIVERABLE';

    public function requiresDns(): bool
    {
        return $this === self::Deliverable;
    }

    public function label(): string
    {
        return match ($this) {
            self::Possible    => 'Anything address-shaped',
            self::Valid       => 'Has a real-looking domain',
            self::Deliverable => 'Domain accepts mail',
        };
    }
}

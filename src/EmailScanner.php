<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email;

use Simtabi\Laranail\Email\Enums\ScanLeniency;
use Simtabi\Laranail\Email\Support\EmailMatch;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;

/**
 * Finds email addresses inside free text.
 *
 * Given a support ticket, a CV, a scraped page or a chat log: *where* are the addresses and what are
 * they. Parsing answers "is this string an address"; this answers "which parts of this string are".
 *
 * ## What this can and cannot claim, said plainly
 *
 * `laranail/phone`'s scanner is backed by the world's numbering plans, so it can reject a candidate
 * on the grounds that no country issues a number like it. **There is no equivalent authority for
 * email**, and pretending otherwise would be the dishonest part of this class. What is here is a
 * deliberately narrow pattern, every candidate re-parsed through {@see Email::parse()}, and a
 * leniency ladder ({@see ScanLeniency}) whose rungs are stated rather than implied.
 *
 * So a match means "this is address-shaped, and passed the bar you asked for". At `Deliverable` that
 * bar includes an MX record, which is the strongest claim obtainable without sending — and still not
 * a claim that the mailbox exists.
 *
 * ## The pattern is narrow on purpose
 *
 * A full RFC 5322 regex is famously enormous, and still wrong: it accepts quoted local parts, comments
 * and folding whitespace that no human writes in prose and no form accepts. Matching that grammar in
 * a document would find more false positives, not fewer. This matches the shape people actually
 * write, then hands it to the parser — the same parser the rest of the package uses, so a scanned
 * address and a submitted one cannot disagree about where the local part ends.
 *
 * Every match carries its byte offset, so the caller can highlight, redact or link in place.
 */
final readonly class EmailScanner
{
    /**
     * The candidate pattern.
     *
     * Three deliberate narrowings, each because the obvious wider version is worse in prose:
     *
     * - **The local part excludes `@`, whitespace and the punctuation people wrap addresses in.**
     *   RFC 5321 permits far more inside quotes; matching it would swallow the sentence around the
     *   address.
     * - **A domain label may not start or end with a hyphen**, which is a DNS rule and rules out
     *   the `--flag` in a pasted command line.
     * - **The match may not begin mid-word.** `(?<![\w.+-])` stops `x-alice@example.com` being read
     *   as `alice@example.com` sitting inside it, which would report an offset that highlights the
     *   wrong span.
     */
    private const string PATTERN = '/(?<![\w.+\-])([A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.\-]+)@([A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*)/';

    public function __construct(
        private DnsResolver $dns,
        private ScanLeniency $leniency = ScanLeniency::Valid,
        private int $limit = PHP_INT_MAX,
    ) {}

    /**
     * Every address in the text, in the order it appears.
     *
     * @return list<EmailMatch>
     */
    public function scan(?string $text, ?ScanLeniency $leniency = null): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $leniency ??= $this->leniency;

        if (preg_match_all(self::PATTERN, $text, $found, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $matches = [];
        $reachable = [];

        foreach ($found[0] as $candidate) {
            if (count($matches) >= $this->limit) {
                break;
            }

            [$raw, $offset] = $candidate;

            $raw = $this->trimTrailingPunctuation($raw);

            if ($raw === '') {
                continue;
            }

            $email = Email::parse($raw);

            if (! $email instanceof Email) {
                continue;
            }

            if (! $this->accepts($email, $leniency, $reachable)) {
                continue;
            }

            $matches[] = new EmailMatch(raw: $raw, offset: $offset, email: $email);
        }

        return $matches;
    }

    /**
     * Replace every address found, offsets handled.
     *
     * Matches are walked in reverse so that replacing one does not move the offsets of the ones
     * before it — the bug every hand-rolled version of this has, and it only shows up on the second
     * address in a document.
     *
     * @param callable(EmailMatch): string $replace
     */
    public function replace(?string $text, callable $replace, ?ScanLeniency $leniency = null): ?string
    {
        if ($text === null) {
            return null;
        }

        foreach (array_reverse($this->scan($text, $leniency)) as $match) {
            $text = substr_replace($text, $replace($match), $match->offset, strlen($match->raw));
        }

        return $text;
    }

    /**
     * Mask every address found, keeping the domain.
     *
     * `alice@example.com` becomes `a••••@example.com`. The domain survives because it is the part
     * that stays useful in a redacted log — knowing a complaint came from a `gov.uk` address, or that
     * forty of them came from one provider, is often the reason the log is being read.
     *
     * Defaults to `Possible`, unlike every other method here. When redacting, a false positive costs
     * a blacked-out word and a false negative leaks a real address, so the asymmetry runs the other
     * way and the default should too.
     */
    public function redact(?string $text, string $maskChar = '•', ?ScanLeniency $leniency = null): ?string
    {
        return $this->replace(
            $text,
            static function (EmailMatch $match) use ($maskChar): string {
                $local = $match->email->localPart;
                $kept = mb_substr($local, 0, 1);

                return $kept . str_repeat($maskChar, max(1, mb_strlen($local) - 1)) . '@' . $match->email->domain;
            },
            $leniency ?? ScanLeniency::Possible,
        );
    }

    /**
     * Trailing punctuation belongs to the sentence, not to the address.
     *
     * "Email me at alice@example.com." ends in a full stop, and a domain may legally end in one too
     * (the DNS root), so the pattern cannot tell them apart — but a person writing prose never means
     * the root. The same goes for the bracket in "(alice@example.com)" and the comma in a list.
     *
     * Leading punctuation is not trimmed here because the pattern's lookbehind already refuses to
     * start a match mid-word, and `<alice@example.com>` from a mail header starts *after* the angle
     * bracket for the same reason.
     */
    private function trimTrailingPunctuation(string $raw): string
    {
        return rtrim($raw, ".,;:!?)]}>'\"");
    }

    /**
     * @param array<string, bool> $reachable Memo of MX answers, one per domain per scan
     */
    private function accepts(Email $email, ScanLeniency $leniency, array &$reachable): bool
    {
        if ($leniency === ScanLeniency::Possible) {
            return true;
        }

        if (! $this->hasPlausibleTld($email->domain)) {
            return false;
        }

        if (! $leniency->requiresDns()) {
            return true;
        }

        // One lookup per distinct domain per scan, for the same reason the batch groups them: a page
        // of addresses is overwhelmingly a handful of providers.
        return $reachable[$email->domain] ??= $this->dns->hasMailExchanger($email->domain);
    }

    /**
     * Whether the domain has a dot and a last label that looks like a TLD.
     *
     * Two or more letters, no digits: that admits every real TLD including the long ones and the
     * internationalised `xn--` ones, and rules out `web-01`, `192.168.1.1` and `service.v2` — the
     * shapes that appear in a pasted command or a version string and are never a contact.
     */
    private function hasPlausibleTld(string $domain): bool
    {
        $labels = explode('.', $domain);

        if (count($labels) < 2) {
            return false;
        }

        $tld = end($labels);

        return preg_match('/^(?:[A-Za-z]{2,}|xn--[A-Za-z0-9\-]{2,})$/', $tld) === 1;
    }
}

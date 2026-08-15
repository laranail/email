<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email;

use Stringable;

/**
 * An email address, parsed once.
 *
 *     $email = Email::parse('Alice+Newsletter@Example.COM');
 *
 *     $email->localPart;      // 'Alice+Newsletter'
 *     $email->domain;         // 'example.com'
 *     $email->mailbox();      // 'alice'      — the plus tag removed
 *     $email->tag();          // 'Newsletter'
 *     $email->canonical();    // 'alice@example.com'
 *
 * Parsing is the point. Every codebase that handles email eventually grows
 * three subtly different `explode('@', …)` calls, and they disagree about the
 * cases that matter.
 *
 * **Splitting is on the LAST `@`, not the first.** A quoted local part may
 * contain one: `"a@b"@example.com` is a legal address whose domain is
 * `example.com`, and splitting on the first `@` gets both halves wrong.
 *
 * **The domain is lowercased; the local part is not.** Domains are
 * case-insensitive by DNS. Local parts are case-SENSITIVE per RFC 5321 §2.4 —
 * most providers choose to ignore case, but that is their policy, not the
 * standard, and a library that lowercases silently is deciding on their
 * behalf. {@see canonical()} exists for when you want that decision made.
 *
 * This class does NOT validate. `Email::parse()` returns null only when the
 * string has no plausible shape at all; whether an address is acceptable is
 * what `laranail/validation`'s rules are for.
 */
final readonly class Email implements Stringable
{
    private function __construct(
        public string $localPart,
        public string $domain,
    ) {}

    /** Parse an address, or null if it has no local part and domain. */
    public static function parse(string $address): ?self
    {
        $address = trim($address);
        $position = mb_strrpos($address, '@');

        if ($position === false || $position === 0 || $position === mb_strlen($address) - 1) {
            return null;
        }

        $local = mb_substr($address, 0, $position);
        $domain = mb_strtolower(trim(mb_substr($address, $position + 1)));

        if ($local === '' || $domain === '' || str_contains($domain, '@')) {
            return null;
        }

        return new self($local, $domain);
    }

    /** The local part with any `+tag` removed — the mailbox mail is delivered to. */
    public function mailbox(): string
    {
        $plus = mb_strpos($this->localPart, '+');

        return $plus === false ? $this->localPart : mb_substr($this->localPart, 0, $plus);
    }

    /** The `+tag`, or null when there is none. */
    public function tag(): ?string
    {
        $plus = mb_strpos($this->localPart, '+');

        if ($plus === false) {
            return null;
        }

        $tag = mb_substr($this->localPart, $plus + 1);

        return $tag === '' ? null : $tag;
    }

    /**
     * The address reduced to one form per mailbox: tag removed, all lowercase.
     *
     * Useful as a deduplication key. Note this is a POLICY, not a fact — it
     * assumes the provider treats case and plus tags the way the large ones
     * do. Storing it as the unique key means two addresses that a strict
     * RFC 5321 server would consider distinct collapse into one.
     */
    public function canonical(): string
    {
        return mb_strtolower($this->mailbox()).'@'.$this->domain;
    }

    /** Whether the domain is the given one, or a subdomain of it. */
    public function isAt(string $domain): bool
    {
        $domain = mb_strtolower(trim($domain, ". \t\n\r\0\x0B"));

        return $this->domain === $domain || str_ends_with($this->domain, '.'.$domain);
    }

    /** Same mailbox, ignoring case and tags. */
    public function equals(self|string $other): bool
    {
        $other = $other instanceof self ? $other : self::parse($other);

        return $other instanceof self && $this->canonical() === $other->canonical();
    }

    public function __toString(): string
    {
        return $this->localPart.'@'.$this->domain;
    }
}

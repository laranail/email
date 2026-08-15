<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email;

use JsonSerializable;
use Simtabi\Laranail\Phone\PhoneBuilder;
use Simtabi\Laranail\Validation\Contracts\Email\DisposableDomainList;
use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;
use Simtabi\Laranail\Validation\Contracts\Email\RoleAccountList;
use Stringable;

/**
 * The fluent entry point: say what you have, then ask what you want.
 *
 * ```php
 * Mail::of('Alice+News@Example.COM')->canonical();      // 'alice@example.com'
 * Mail::of($address)->isDisposable();                    // true for a throwaway provider
 * Mail::of($address)->isReachable();                     // MX lookup, cached
 * Mail::of($address)->problems();                        // everything wrong with it, at once
 * ```
 *
 * Shaped deliberately like `laranail/phone`'s {@see PhoneBuilder}: the two
 * packages solve the same class of problem — a contact identifier that arrives as a string, is
 * dirtier than it looks, and needs to be normalised, judged and stored — and a developer who has
 * learned one should not have to learn the other.
 *
 * Narrowing returns a new instance and the parse is memoised on first read, so a builder can be held
 * and reused without a later call changing what an earlier caller sees.
 *
 * ## What this is not
 *
 * It does not validate. `laranail/validation`'s rules do that, and they use this underneath. The
 * question "is this acceptable" belongs to a form; the questions here are "what is this" and "what
 * is wrong with it".
 */
final class EmailBuilder implements JsonSerializable, Stringable
{
    private ?Email $resolved = null;

    private bool $parsed = false;

    public function __construct(
        private readonly ?string $input,
        private readonly bool $treatSubaddressAsDistinct = false,
    ) {}

    // ---------------------------------------------------------------- narrowing

    /**
     * Treat `alice+news@example.com` and `alice@example.com` as different addresses.
     *
     * Off by default, because for deduplication they are the same mailbox and treating them
     * otherwise is how one person signs up eleven times. On when the tag is meaningful to you —
     * routing, per-signup tracking — and you need it preserved.
     */
    public function keepSubaddress(bool $keep = true): self
    {
        return new self($this->input, $keep);
    }

    // ---------------------------------------------------------------- the value

    /** The parsed address, or null when the string has no plausible shape. */
    public function value(): ?Email
    {
        if (! $this->parsed) {
            $this->resolved = $this->input === null ? null : Email::parse($this->input);
            $this->parsed = true;
        }

        return $this->resolved;
    }

    public function isParseable(): bool
    {
        return $this->value() !== null;
    }

    public function localPart(): ?string
    {
        return $this->value()?->localPart;
    }

    public function domain(): ?string
    {
        return $this->value()?->domain;
    }

    public function mailbox(): ?string
    {
        return $this->value()?->mailbox();
    }

    public function tag(): ?string
    {
        return $this->value()?->tag();
    }

    /**
     * The form to store and compare on.
     *
     * Lowercased and, unless {@see keepSubaddress()} was called, with the `+tag` removed. That is a
     * *policy* rather than a standard — RFC 5321 makes local parts case-sensitive — which is exactly
     * why it is a separate method rather than something `parse()` does silently.
     */
    public function canonical(): ?string
    {
        $email = $this->value();

        if ($email === null) {
            return null;
        }

        return $this->treatSubaddressAsDistinct
            ? mb_strtolower($email->localPart).'@'.$email->domain
            : $email->canonical();
    }

    public function isAt(string $domain): bool
    {
        return $this->value()?->isAt($domain) ?? false;
    }

    public function equals(Email|string|null $other): bool
    {
        if ($other === null) {
            return false;
        }

        $mine = $this->canonical();

        if ($mine === null) {
            return false;
        }

        $theirs = $other instanceof Email
            ? (new self((string) $other, $this->treatSubaddressAsDistinct))->canonical()
            : (new self($other, $this->treatSubaddressAsDistinct))->canonical();

        return $mine === $theirs;
    }

    // ---------------------------------------------------------------- judgements

    /**
     * Whether the domain is a known throwaway provider.
     *
     * A maintained list, refreshed by `laranail::email.refresh-lists`. It is a moving target and the
     * list is never complete — treat a false as "not on the list", never as "durable".
     */
    public function isDisposable(): bool
    {
        $domain = $this->domain();

        return $domain !== null && app(DisposableDomainList::class)->contains($domain);
    }

    /**
     * Whether the local part names a function rather than a person — `info`, `support`, `noreply`.
     *
     * Worth knowing before treating an address as an individual: a role account is usually a shared
     * inbox, so a password reset sent to it reaches everyone who can read it.
     */
    public function isRoleAccount(): bool
    {
        $local = $this->mailbox();

        return $local !== null && app(RoleAccountList::class)->contains($local);
    }

    /**
     * Whether the domain has a mail exchanger, cached.
     *
     * The cheapest deliverability signal that is worth anything, and the limit of what can be known
     * without sending: an MX record means somebody accepts mail for the domain. It says nothing
     * about whether *this mailbox* exists.
     */
    public function isReachable(): bool
    {
        $domain = $this->domain();

        return $domain !== null && app(DnsResolver::class)->hasMailExchanger($domain);
    }

    /**
     * Everything wrong with the address, in one pass.
     *
     * Returning the whole set rather than the first failure, because a form that reports one problem
     * per submission makes the user discover them one round trip at a time.
     *
     * @param  bool  $checkReachability  Perform the MX lookup. Off by default: it is the only check here
     *                                   that touches the network, and a caller iterating a list of ten
     *                                   thousand addresses should opt into that deliberately.
     * @return list<string>
     */
    public function problems(bool $checkReachability = false): array
    {
        if (! $this->isParseable()) {
            return ['unparseable'];
        }

        $problems = [];

        if ($this->isDisposable()) {
            $problems[] = 'disposable';
        }

        if ($this->isRoleAccount()) {
            $problems[] = 'role_account';
        }

        if ($checkReachability && ! $this->isReachable()) {
            $problems[] = 'unreachable';
        }

        return $problems;
    }

    /** Whether the address passes every check. */
    public function isUsable(bool $checkReachability = false): bool
    {
        return $this->problems($checkReachability) === [];
    }

    // ---------------------------------------------------------------- output

    /**
     * @return array{address: string|null, local_part: string|null, domain: string|null, mailbox: string|null, tag: string|null, canonical: string|null, disposable: bool, role_account: bool}
     */
    public function toArray(): array
    {
        return [
            'address' => $this->value() === null ? null : (string) $this->value(),
            'local_part' => $this->localPart(),
            'domain' => $this->domain(),
            'mailbox' => $this->mailbox(),
            'tag' => $this->tag(),
            'canonical' => $this->canonical(),
            'disposable' => $this->isDisposable(),
            'role_account' => $this->isRoleAccount(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return (string) ($this->value() ?? $this->input ?? '');
    }
}

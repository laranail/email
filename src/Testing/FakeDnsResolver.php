<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Testing;

use Simtabi\Laranail\Validation\Contracts\Email\DnsResolver;

/**
 * A resolver that answers from a list and records what it was asked.
 *
 *     $dns = FakeDnsResolver::deliverable('example.com');
 *     app()->instance(DnsResolver::class, $dns);
 *     // ...
 *     expect($dns->asked())->toBe(['example.com']);
 *
 * Shipped rather than left to each application to rewrite, because the entire
 * reason deliverability sits behind a contract is that a test should never
 * make a DNS query — and a fake that is reimplemented per project is one that
 * drifts from the contract it stands in for.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var list<string> */
    private array $asked = [];

    /** @param  list<string>  $deliverable */
    public function __construct(
        private readonly array $deliverable = [],
        private readonly bool $default = false,
    ) {}

    public static function deliverable(string ...$domains): self
    {
        return new self(array_values($domains));
    }

    /** Every domain resolves — the "DNS is fine, do not make it the subject" fake. */
    public static function everything(): self
    {
        return new self(default: true);
    }

    /** Nothing resolves. Note this is NOT what an outage looks like; see the contract. */
    public static function nothing(): self
    {
        return new self;
    }

    public function hasMailExchanger(string $domain): bool
    {
        $domain = mb_strtolower(trim($domain));
        $this->asked[] = $domain;

        return $this->deliverable === [] ? $this->default : in_array($domain, $this->deliverable, true);
    }

    /** @return list<string> */
    public function asked(): array
    {
        return $this->asked;
    }

    public function wasAsked(string $domain): bool
    {
        return in_array(mb_strtolower(trim($domain)), $this->asked, true);
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Support;

use JsonSerializable;
use Simtabi\Laranail\Email\Email;

/**
 * One row of a batch: the input, what it parsed to, and everything wrong with it.
 *
 * `index` is the position in the *input*, not in the output, and it survives filtering. An audit
 * whose caller cannot map a verdict back to the row it came from is a report nobody can act on —
 * "42 unusable addresses" is not something you can fix, and "rows 7, 19 and 104" is.
 *
 * The shape deliberately mirrors `laranail/phone`'s entry. The two packages judge the same kind of
 * thing — a contact identifier that arrived as a string and is dirtier than it looks — and a
 * developer who has read one import script should be able to read the other.
 */
final readonly class EmailAuditEntry implements JsonSerializable
{
    /**
     * @param int $index Position in the input list
     * @param string|null $input Exactly what was supplied, unmodified
     * @param list<string> $problems Every failure, not the first one
     * @param bool|null $reachable Null when reachability was not checked at all
     * @param int|null $duplicateOf The index of the first row with the same canonical address
     */
    public function __construct(
        public int $index,
        public ?string $input,
        public ?Email $email,
        public ?string $canonical,
        public array $problems,
        public ?bool $reachable = null,
        public ?int $duplicateOf = null,
    ) {}

    public function isParseable(): bool
    {
        return $this->email instanceof Email;
    }

    /** Whether every check the batch actually ran came back clean. */
    public function isUsable(): bool
    {
        return $this->problems === [];
    }

    public function isDuplicate(): bool
    {
        return $this->duplicateOf !== null;
    }

    public function domain(): ?string
    {
        return $this->email?->domain;
    }

    public function hasProblem(string $problem): bool
    {
        return in_array($problem, $this->problems, true);
    }

    /**
     * @return array{
     *     index: int,
     *     input: string|null,
     *     parseable: bool,
     *     usable: bool,
     *     address: string|null,
     *     canonical: string|null,
     *     local_part: string|null,
     *     domain: string|null,
     *     mailbox: string|null,
     *     tag: string|null,
     *     problems: list<string>,
     *     reachable: bool|null,
     *     duplicate_of: int|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'index'        => $this->index,
            'input'        => $this->input,
            'parseable'    => $this->isParseable(),
            'usable'       => $this->isUsable(),
            'address'      => $this->email === null ? null : (string) $this->email,
            'canonical'    => $this->canonical,
            'local_part'   => $this->email?->localPart,
            'domain'       => $this->email?->domain,
            'mailbox'      => $this->email?->mailbox(),
            'tag'          => $this->email?->tag(),
            'problems'     => $this->problems,
            'reachable'    => $this->reachable,
            'duplicate_of' => $this->duplicateOf,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

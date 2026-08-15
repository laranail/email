<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Support;

use JsonSerializable;
use Simtabi\Laranail\Email\Email;

/**
 * One address found inside a body of text.
 *
 * The offset is the point. Without it a caller wanting to highlight or redact has to search the text
 * again for the matched string, which finds the *first* occurrence rather than *this* one — and a
 * message that mentions an address twice gets the wrong instance replaced.
 *
 * Offsets are **byte** offsets, so `substr_replace()` and `substr()` work on them directly. In a
 * multi-byte document they are not character positions.
 */
final readonly class EmailMatch implements JsonSerializable
{
    public function __construct(
        /** Exactly as it appeared, without any surrounding punctuation the scanner trimmed. */
        public string $raw,
        /** Byte offset of the first character of {@see $raw} in the scanned text. */
        public int $offset,
        public Email $email,
    ) {}

    /** The offset one past the end of the match. */
    public function end(): int
    {
        return $this->offset + strlen($this->raw);
    }

    /**
     * @return array{raw: string, offset: int, end: int, address: string, canonical: string, domain: string}
     */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'offset' => $this->offset,
            'end' => $this->end(),
            'address' => (string) $this->email,
            'canonical' => $this->email->canonical(),
            'domain' => $this->email->domain,
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

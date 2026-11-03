<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Decides whether a file can travel as an attachment.
 *
 * Mail providers limit the size of the whole message after encoding, not
 * the size of the file: base64 turns every 3 bytes into 4 characters and
 * MIME adds a line break every 76 characters, so a file grows by about 37%.
 */
final readonly class AttachmentPolicy
{
    public function __construct(
        public int $maxMessageBytes,
        public int $headroomBytes,
    ) {}

    /**
     * Size of a file once base64-encoded in 76-character lines ending in CRLF.
     */
    public static function encodedSize(int $bytes): int
    {
        $encoded = 4 * intdiv($bytes + 2, 3);

        return $encoded + 2 * intdiv($encoded + 75, 76);
    }

    public function fits(int $bytes): bool
    {
        return self::encodedSize($bytes) + $this->headroomBytes <= $this->maxMessageBytes;
    }

    /**
     * The largest file that still fits, for logs and documentation.
     */
    public function largestAttachment(): int
    {
        $low = 0;
        $high = $this->maxMessageBytes;

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($this->fits($middle)) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }
}

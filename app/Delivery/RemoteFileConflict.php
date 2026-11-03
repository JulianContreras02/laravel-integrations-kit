<?php

declare(strict_types=1);

namespace App\Delivery;

use RuntimeException;

/**
 * A different file with the same name is already in the partner's inbox.
 * Retrying cannot fix that, and overwriting it silently could replace a
 * file the partner already imported: a person has to decide.
 */
final class RemoteFileConflict extends RuntimeException
{
    public static function sizeMismatch(string $remotePath, int $remoteSize, int $expectedSize): self
    {
        return new self(sprintf(
            'The partner already has %s with %d bytes, but the report has %d bytes. Not overwriting it.',
            $remotePath,
            $remoteSize,
            $expectedSize,
        ));
    }
}

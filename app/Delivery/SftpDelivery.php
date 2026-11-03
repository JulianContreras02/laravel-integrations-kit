<?php

declare(strict_types=1);

namespace App\Delivery;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Throwable;

/**
 * Copies a stored file to the partner's SFTP inbox, safely repeatable.
 *
 * - Idempotent: if the file is already there with the expected size, the
 *   upload is skipped, so a retried job never delivers twice.
 * - Atomic for the reader: the bytes go to "<name>.part" and are renamed at
 *   the end, so the partner's importer never picks up a half-written file.
 * - Streamed: the file goes from S3 to SFTP without being held in memory.
 *
 * Two workers must not deliver the same file at the same time; the
 * pipeline job guarantees that with a per-report lock.
 */
final class SftpDelivery
{
    /**
     * @param  list<int>  $backoffMilliseconds  pause before each retry
     */
    public function __construct(
        private readonly FilesystemFactory $storage,
        private readonly string $remoteDisk,
        private readonly array $backoffMilliseconds = [1_000, 5_000],
    ) {}

    public function deliver(string $sourceDisk, string $sourcePath, string $remotePath): SftpDeliveryResult
    {
        $source = $this->storage->disk($sourceDisk);
        $expectedSize = $source->size($sourcePath);

        /** @var SftpDeliveryResult */
        return retry(
            $this->backoffMilliseconds,
            fn (int $attempt): SftpDeliveryResult => $this->attempt($source, $sourcePath, $remotePath, $expectedSize, $attempt),
            // Only transport errors are worth retrying; a conflict is not.
            when: fn (Throwable $exception): bool => $exception instanceof FilesystemException,
        );
    }

    private function attempt(Filesystem $source, string $sourcePath, string $remotePath, int $expectedSize, int $attempt): SftpDeliveryResult
    {
        $remote = $this->storage->disk($this->remoteDisk);

        if ($remote->exists($remotePath)) {
            $remoteSize = $remote->size($remotePath);

            if ($remoteSize !== $expectedSize) {
                throw RemoteFileConflict::sizeMismatch($remotePath, $remoteSize, $expectedSize);
            }

            return new SftpDeliveryResult($remotePath, alreadyDelivered: true, attempts: $attempt);
        }

        $partialPath = $remotePath.'.part';
        $stream = $source->readStream($sourcePath);

        if ($stream === null) {
            throw new RuntimeException("Could not read {$sourcePath} from the source disk.");
        }

        try {
            // Overwrites a ".part" left behind by a crashed attempt.
            $remote->writeStream($partialPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $remote->move($partialPath, $remotePath);

        return new SftpDeliveryResult($remotePath, alreadyDelivered: false, attempts: $attempt);
    }
}

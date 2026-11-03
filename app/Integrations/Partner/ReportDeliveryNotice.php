<?php

declare(strict_types=1);

namespace App\Integrations\Partner;

use Carbon\CarbonImmutable;

/**
 * Tells the partner which file we dropped in their SFTP inbox, so they can
 * check its integrity before importing it.
 */
final readonly class ReportDeliveryNotice
{
    public function __construct(
        public string $reportKey,
        public string $fileName,
        public string $sha256,
        public int $sizeBytes,
        public int $rowCount,
        public CarbonImmutable $deliveredAt,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'report_key' => $this->reportKey,
            'file_name' => $this->fileName,
            'sha256' => $this->sha256,
            'size_bytes' => $this->sizeBytes,
            'row_count' => $this->rowCount,
            'delivered_at' => $this->deliveredAt->toIso8601ZuluString(),
        ];
    }
}

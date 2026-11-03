<?php

declare(strict_types=1);

namespace App\Reports;

final readonly class ExportedReport
{
    public function __construct(
        public string $path,
        public string $fileName,
        public int $rowCount,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}

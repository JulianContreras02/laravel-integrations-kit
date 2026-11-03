<?php

declare(strict_types=1);

namespace App\Delivery;

final readonly class SftpDeliveryResult
{
    public function __construct(
        public string $remotePath,
        public bool $alreadyDelivered,
        public int $attempts,
    ) {}
}

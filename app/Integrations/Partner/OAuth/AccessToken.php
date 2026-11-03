<?php

declare(strict_types=1);

namespace App\Integrations\Partner\OAuth;

use Carbon\CarbonImmutable;

final readonly class AccessToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $value,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * A short, non-reversible identifier that is safe to log or print.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->value), 0, 12);
    }

    public function is(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}

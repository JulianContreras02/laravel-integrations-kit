<?php

declare(strict_types=1);

namespace App\Integrations\Partner\OAuth;

final readonly class ClientCredentials
{
    public function __construct(
        public string $tokenUrl,
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public ?string $scope = null,
    ) {}

    /**
     * Identifies the token these credentials produce, without the secret.
     */
    public function cacheKey(): string
    {
        return 'partner-oauth:token:'.hash('sha256', $this->tokenUrl.'|'.$this->clientId.'|'.$this->scope);
    }
}

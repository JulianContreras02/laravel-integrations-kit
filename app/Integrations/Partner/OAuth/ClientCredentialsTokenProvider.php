<?php

declare(strict_types=1);

namespace App\Integrations\Partner\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Obtains bearer tokens with the OAuth2 client credentials grant (RFC 6749,
 * section 4.4) and keeps them in a shared cache for as long as they are valid.
 */
final class ClientCredentialsTokenProvider
{
    /**
     * Used when the server omits expires_in (it is only RECOMMENDED by the
     * RFC). Short on purpose: a 401 also triggers a refresh, so guessing low
     * only costs an extra token request.
     */
    private const int DEFAULT_EXPIRES_IN = 300;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Repository $cache,
        private readonly ClientCredentials $credentials,
        private readonly int $timeoutSeconds = 5,
        private readonly int $expiryMarginSeconds = 60,
    ) {}

    public function token(): AccessToken
    {
        return $this->cached() ?? $this->requestAndStore();
    }

    /**
     * Drops the cached token, for example after the API rejected it.
     */
    public function forget(): void
    {
        $this->cache->forget($this->credentials->cacheKey());
    }

    private function cached(): ?AccessToken
    {
        $cached = $this->cache->get($this->credentials->cacheKey());

        if (! is_array($cached) || ! is_string($cached['value'] ?? null) || ! is_int($cached['expires_at'] ?? null)) {
            return null;
        }

        return new AccessToken($cached['value'], CarbonImmutable::createFromTimestamp($cached['expires_at']));
    }

    private function requestAndStore(): AccessToken
    {
        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->withBasicAuth($this->credentials->clientId, $this->credentials->clientSecret)
            ->timeout($this->timeoutSeconds)
            ->post($this->credentials->tokenUrl, array_filter([
                'grant_type' => 'client_credentials',
                'scope' => $this->credentials->scope,
            ]));

        if ($response->failed()) {
            throw TokenRequestFailed::rejected($response);
        }

        $value = $response->json('access_token');
        $expiresIn = $response->json('expires_in', self::DEFAULT_EXPIRES_IN);

        if (! is_string($value) || $value === '' || ! is_numeric($expiresIn)) {
            throw TokenRequestFailed::malformed($response);
        }

        $expiresIn = (int) $expiresIn;
        $token = new AccessToken($value, CarbonImmutable::now()->addSeconds($expiresIn));

        // Stop using the token a little before the server does. Very short
        // lifetimes keep at least half of their life instead of going negative.
        $ttl = $expiresIn - min($this->expiryMarginSeconds, intdiv($expiresIn, 2));

        $this->cache->put(
            $this->credentials->cacheKey(),
            ['value' => $token->value, 'expires_at' => $token->expiresAt->getTimestamp()],
            $ttl,
        );

        return $token;
    }
}

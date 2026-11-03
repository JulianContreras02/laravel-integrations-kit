<?php

declare(strict_types=1);

namespace App\Integrations\Partner\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use LogicException;

/**
 * Obtains bearer tokens with the OAuth2 client credentials grant (RFC 6749,
 * section 4.4) and keeps them in a shared cache for as long as they are valid.
 *
 * When the token is missing or expired, many workers notice at the same
 * time. Only the one holding the refresh lock asks the partner for a new
 * token; the others wait for the lock and then read what it stored.
 */
final class ClientCredentialsTokenProvider
{
    /**
     * Used when the server omits expires_in (it is only RECOMMENDED by the
     * RFC). Short on purpose: a 401 also triggers a refresh, so guessing low
     * only costs an extra token request.
     */
    private const int DEFAULT_EXPIRES_IN = 300;

    private readonly LockProvider $locks;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Repository $cache,
        private readonly ClientCredentials $credentials,
        private readonly int $timeoutSeconds = 5,
        private readonly int $expiryMarginSeconds = 60,
        private readonly int $lockSeconds = 15,
        private readonly int $lockWaitSeconds = 10,
    ) {
        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The OAuth token cache store must support atomic locks.');
        }

        $this->locks = $store;
    }

    public function token(): AccessToken
    {
        return $this->cached() ?? $this->refresh();
    }

    /**
     * Drops the cached token after the API rejected it, unless another worker
     * already replaced it: forgetting a fresh token would only cause another
     * round of refreshes.
     */
    public function forget(AccessToken $rejected): void
    {
        $this->lock()->block($this->lockWaitSeconds, function () use ($rejected): void {
            if ($this->cached()?->is($rejected) === true) {
                $this->cache->forget($this->credentials->cacheKey());
            }
        });
    }

    private function refresh(): AccessToken
    {
        /** @var AccessToken $token block() hands back what the callback returned. */
        $token = $this->lock()->block(
            $this->lockWaitSeconds,
            // Check again under the lock: the worker we were waiting for has
            // probably stored a fresh token already.
            fn (): AccessToken => $this->cached() ?? $this->requestAndStore(),
        );

        return $token;
    }

    private function lock(): Lock
    {
        return $this->locks->lock($this->credentials->cacheKey().':refresh', $this->lockSeconds);
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

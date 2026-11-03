<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integrations\Partner\OAuth\ClientCredentials;
use App\Integrations\Partner\OAuth\ClientCredentialsTokenProvider;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClientCredentialsTokenProvider::class, function (Application $app): ClientCredentialsTokenProvider {
            return new ClientCredentialsTokenProvider(
                http: $app->make(HttpFactory::class),
                cache: $app->make(CacheFactory::class)->store(self::optionalString('partner.oauth.cache_store')),
                credentials: new ClientCredentials(
                    tokenUrl: Config::string('partner.oauth.token_url'),
                    clientId: Config::string('partner.oauth.client_id'),
                    clientSecret: Config::string('partner.oauth.client_secret'),
                    scope: self::optionalString('partner.oauth.scope'),
                ),
                timeoutSeconds: Config::integer('partner.oauth.timeout_seconds'),
                expiryMarginSeconds: Config::integer('partner.oauth.expiry_margin_seconds'),
                lockSeconds: Config::integer('partner.oauth.lock_seconds'),
                lockWaitSeconds: Config::integer('partner.oauth.lock_wait_seconds'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Reads a config value that may be unset (null or empty string).
     */
    private static function optionalString(string $key): ?string
    {
        $value = Config::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

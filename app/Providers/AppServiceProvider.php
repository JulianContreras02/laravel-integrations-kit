<?php

declare(strict_types=1);

namespace App\Providers;

use App\Delivery\SftpDelivery;
use App\Integrations\Partner\OAuth\ClientCredentials;
use App\Integrations\Partner\OAuth\ClientCredentialsTokenProvider;
use App\Integrations\Partner\PartnerApiClient;
use App\Mail\AttachmentPolicy;
use App\Mail\ReportMailer;
use App\Reports\DailyOrdersExporter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
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

        $this->app->singleton(PartnerApiClient::class, fn (Application $app): PartnerApiClient => new PartnerApiClient(
            http: $app->make(HttpFactory::class),
            tokens: $app->make(ClientCredentialsTokenProvider::class),
            baseUrl: Config::string('partner.api.base_url'),
            timeoutSeconds: Config::integer('partner.api.timeout_seconds'),
        ));

        $this->app->bind(DailyOrdersExporter::class, fn (Application $app): DailyOrdersExporter => new DailyOrdersExporter(
            storage: $app->make(FilesystemFactory::class),
            disk: Config::string('reports.disk'),
            directory: Config::string('reports.directory'),
            timezone: Config::string('reports.timezone'),
            chunkSize: Config::integer('reports.chunk_size'),
            temporaryDirectory: self::optionalString('reports.temp_directory'),
        ));

        $this->app->bind(SftpDelivery::class, fn (Application $app): SftpDelivery => new SftpDelivery(
            storage: $app->make(FilesystemFactory::class),
            remoteDisk: Config::string('partner.sftp.disk'),
            backoffMilliseconds: array_values(array_filter(Config::array('partner.sftp.backoff_ms'), is_int(...))),
        ));

        $this->app->bind(AttachmentPolicy::class, fn (): AttachmentPolicy => new AttachmentPolicy(
            maxMessageBytes: Config::integer('reports.mail.max_message_bytes'),
            headroomBytes: Config::integer('reports.mail.headroom_bytes'),
        ));

        $this->app->bind(ReportMailer::class, fn (Application $app): ReportMailer => new ReportMailer(
            url: $app->make(UrlGenerator::class),
            policy: $app->make(AttachmentPolicy::class),
            recipients: array_values(array_filter(Config::array('reports.mail.to'), is_string(...))),
            timezone: Config::string('reports.timezone'),
            disk: Config::string('reports.disk'),
            linkTtlDays: Config::integer('reports.mail.link_ttl_days'),
        ));
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

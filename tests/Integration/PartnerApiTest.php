<?php

declare(strict_types=1);

use App\Integrations\Partner\OAuth\ClientCredentialsTokenProvider;
use App\Integrations\Partner\PartnerApiClient;
use App\Integrations\Partner\ReportDeliveryNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

const TOKEN_REQUESTS = ['method' => 'POST', 'url' => '/oauth/token'];

function integrationNotice(string $reportKey): ReportDeliveryNotice
{
    return new ReportDeliveryNotice($reportKey, 'daily-orders-2031-01-15.xlsx', str_repeat('b', 64), 2_048, 20, CarbonImmutable::now());
}

it('gets a token from the partner once and reuses it', function () {
    $before = wiremockCount(TOKEN_REQUESTS);

    $tokens = app(ClientCredentialsTokenProvider::class);
    $first = $tokens->token();
    $second = $tokens->token();

    expect($first->is($second))->toBeTrue()
        ->and(wiremockCount(TOKEN_REQUESTS) - $before)->toBe(1);
});

it('registers a delivery with the partner', function () {
    $id = app(PartnerApiClient::class)->registerDelivery(integrationNotice('it-'.Str::random(8)));

    expect(Str::isUuid($id))->toBeTrue();
});

it('recovers when the partner revokes the cached token', function () {
    $reportKey = 'it-revoked-'.Str::random(8);

    // The first registration for this key answers 401, as if the token had
    // been revoked; afterwards the normal stub accepts it.
    $stub = wiremock()->post('/mappings', [
        'scenarioName' => $reportKey,
        'requiredScenarioState' => 'Started',
        'newScenarioState' => 'token-replaced',
        'priority' => 0,
        'request' => [
            'method' => 'POST',
            'urlPath' => '/v1/report-deliveries',
            'headers' => ['Idempotency-Key' => ['equalTo' => $reportKey]],
        ],
        'response' => ['status' => 401, 'jsonBody' => ['error' => 'invalid_token']],
    ])->throw()->json('id');

    try {
        $before = wiremockCount(TOKEN_REQUESTS);

        $id = app(PartnerApiClient::class)->registerDelivery(integrationNotice($reportKey));

        expect(Str::isUuid($id))->toBeTrue()
            ->and(wiremockCount(TOKEN_REQUESTS) - $before)->toBe(2);
    } finally {
        wiremock()->delete("/mappings/{$stub}");
    }
});

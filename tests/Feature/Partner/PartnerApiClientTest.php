<?php

declare(strict_types=1);

use App\Integrations\Partner\PartnerApiClient;
use App\Integrations\Partner\ReportDeliveryNotice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('partner.api.base_url', 'https://partner.test');
    config()->set('partner.oauth.token_url', 'https://partner.test/oauth/token');
    config()->set('partner.oauth.client_id', 'test-client');
    config()->set('partner.oauth.client_secret', 'test-secret');
});

function deliveryNotice(): ReportDeliveryNotice
{
    return new ReportDeliveryNotice(
        reportKey: 'daily-orders:2026-11-02',
        fileName: 'daily-orders-2026-11-02.xlsx',
        sha256: str_repeat('a', 64),
        sizeBytes: 123_456,
        rowCount: 1_500,
        deliveredAt: CarbonImmutable::parse('2026-11-03 11:00:05', 'UTC'),
    );
}

it('registers a delivery with a bearer token and an idempotency key', function () {
    Http::fake([
        'partner.test/oauth/token' => tokenResponse('valid-token'),
        'partner.test/v1/report-deliveries' => Http::response(['id' => 'dlv_123'], 201),
    ]);

    $id = app(PartnerApiClient::class)->registerDelivery(deliveryNotice());

    expect($id)->toBe('dlv_123');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://partner.test/v1/report-deliveries'
        && $request->hasHeader('Authorization', 'Bearer valid-token')
        && $request->hasHeader('Idempotency-Key', 'daily-orders:2026-11-02')
        && $request['sha256'] === str_repeat('a', 64)
        && $request['row_count'] === 1_500
        && $request['delivered_at'] === '2026-11-03T11:00:05Z');
});

it('gets a new token and retries once when the partner rejects the cached one', function () {
    Http::fake([
        'partner.test/oauth/token' => Http::sequence()
            ->push(tokenResponse('revoked-token'))
            ->push(tokenResponse('fresh-token')),
        'partner.test/v1/report-deliveries' => Http::sequence()
            ->push(['error' => 'invalid_token'], 401)
            ->push(['id' => 'dlv_456'], 201),
    ]);

    $id = app(PartnerApiClient::class)->registerDelivery(deliveryNotice());

    expect($id)->toBe('dlv_456');

    $apiCalls = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/v1/'))->values();
    expect($apiCalls)->toHaveCount(2)
        ->and($apiCalls[0][0]->header('Authorization'))->toBe(['Bearer revoked-token'])
        ->and($apiCalls[1][0]->header('Authorization'))->toBe(['Bearer fresh-token']);
});

it('gives up after one retry when the new token is rejected too', function () {
    Http::fake([
        'partner.test/oauth/token' => Http::sequence()
            ->push(tokenResponse('token-one'))
            ->push(tokenResponse('token-two')),
        'partner.test/v1/report-deliveries' => Http::response(['error' => 'invalid_token'], 401),
    ]);

    expect(fn () => app(PartnerApiClient::class)->registerDelivery(deliveryNotice()))
        ->toThrow(RequestException::class);

    Http::assertSentCount(4);
});

it('reuses the cached token across calls', function () {
    Http::fake([
        'partner.test/oauth/token' => tokenResponse('shared-token'),
        'partner.test/v1/report-deliveries' => Http::response(['id' => 'dlv_789'], 201),
    ]);

    app(PartnerApiClient::class)->registerDelivery(deliveryNotice());
    app(PartnerApiClient::class)->registerDelivery(deliveryNotice());

    Http::assertSentCount(3);
});

it('fails loudly when the partner returns no id', function () {
    Http::fake([
        'partner.test/oauth/token' => tokenResponse('valid-token'),
        'partner.test/v1/report-deliveries' => Http::response(['status' => 'received'], 201),
    ]);

    app(PartnerApiClient::class)->registerDelivery(deliveryNotice());
})->throws(UnexpectedValueException::class);

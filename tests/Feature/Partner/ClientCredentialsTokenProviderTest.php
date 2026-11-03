<?php

declare(strict_types=1);

use App\Integrations\Partner\OAuth\ClientCredentialsTokenProvider;
use App\Integrations\Partner\OAuth\TokenRequestFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('partner.oauth.token_url', 'https://partner.test/oauth/token');
    config()->set('partner.oauth.client_id', 'test-client');
    config()->set('partner.oauth.client_secret', 'test-secret');
    config()->set('partner.oauth.scope', 'reports:write');
    config()->set('partner.oauth.expiry_margin_seconds', 60);
});

function tokenResponse(string $token, ?int $expiresIn = 3600): array
{
    return array_filter([
        'access_token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => $expiresIn,
    ]);
}

function tokens(): ClientCredentialsTokenProvider
{
    return app(ClientCredentialsTokenProvider::class);
}

it('requests a token once and reuses it from the cache', function () {
    Http::fake(['partner.test/oauth/token' => Http::sequence()
        ->push(tokenResponse('first-token'))
        ->push(tokenResponse('second-token')),
    ]);

    expect(tokens()->token()->value)->toBe('first-token')
        ->and(tokens()->token()->value)->toBe('first-token')
        ->and(tokens()->token()->value)->toBe('first-token');

    Http::assertSentCount(1);
});

it('sends the client credentials with HTTP Basic auth and the configured scope', function () {
    Http::fake(['partner.test/oauth/token' => tokenResponse('a-token')]);

    tokens()->token();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://partner.test/oauth/token'
        && $request->isForm()
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-client:test-secret'))
        && $request['grant_type'] === 'client_credentials'
        && $request['scope'] === 'reports:write');
});

it('asks for a new token shortly before the current one expires', function () {
    Http::fake(['partner.test/oauth/token' => Http::sequence()
        ->push(tokenResponse('first-token', expiresIn: 3600))
        ->push(tokenResponse('second-token', expiresIn: 3600)),
    ]);

    expect(tokens()->token()->value)->toBe('first-token');

    $this->travel(3600 - 60 - 1)->seconds();
    expect(tokens()->token()->value)->toBe('first-token');

    $this->travel(2)->seconds();
    expect(tokens()->token()->value)->toBe('second-token');

    Http::assertSentCount(2);
});

it('keeps at least half of a very short token lifetime', function () {
    Http::fake(['partner.test/oauth/token' => Http::sequence()
        ->push(tokenResponse('short-lived', expiresIn: 30))
        ->push(tokenResponse('next-one', expiresIn: 30)),
    ]);

    expect(tokens()->token()->value)->toBe('short-lived');

    $this->travel(14)->seconds();
    expect(tokens()->token()->value)->toBe('short-lived');

    $this->travel(2)->seconds();
    expect(tokens()->token()->value)->toBe('next-one');
});

it('assumes a short lifetime when the server omits expires_in', function () {
    Http::fake(['partner.test/oauth/token' => tokenResponse('no-expiry', expiresIn: null)]);

    $token = tokens()->token();

    expect($token->expiresAt->timestamp)->toBe(now()->addSeconds(300)->timestamp);
});

it('explains why the partner refused to issue a token, without leaking the secret', function () {
    Http::fake(['partner.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

    try {
        tokens()->token();
        $this->fail('Expected the token request to fail.');
    } catch (TokenRequestFailed $exception) {
        expect($exception->getMessage())
            ->toContain('HTTP 401, invalid_client')
            ->not->toContain('test-secret');
    }
});

it('rejects a successful response without an access token', function () {
    Http::fake(['partner.test/oauth/token' => Http::response(['token_type' => 'Bearer'])]);

    tokens()->token();
})->throws(TokenRequestFailed::class, 'without a usable access_token');

it('never caches a failed token request', function () {
    Http::fake(['partner.test/oauth/token' => Http::sequence()
        ->push(['error' => 'temporarily_unavailable'], 503)
        ->push(tokenResponse('after-outage')),
    ]);

    expect(fn () => tokens()->token())->toThrow(TokenRequestFailed::class);
    expect(tokens()->token()->value)->toBe('after-outage');
});

it('prints a fingerprint of the token, never the token itself', function () {
    Http::fake(['partner.test/oauth/token' => tokenResponse('super-secret-token')]);

    $this->artisan('partner:token')
        ->expectsOutputToContain(substr(hash('sha256', 'super-secret-token'), 0, 12))
        ->doesntExpectOutputToContain('super-secret-token')
        ->assertSuccessful();
});

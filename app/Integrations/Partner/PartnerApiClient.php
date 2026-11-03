<?php

declare(strict_types=1);

namespace App\Integrations\Partner;

use App\Integrations\Partner\OAuth\AccessToken;
use App\Integrations\Partner\OAuth\ClientCredentialsTokenProvider;
use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use UnexpectedValueException;

final class PartnerApiClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ClientCredentialsTokenProvider $tokens,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 10,
    ) {}

    /**
     * Registers a delivered file and returns the partner's id for it.
     *
     * The report key doubles as Idempotency-Key: if we registered the file
     * but crashed before recording it, the retry is recognised as a repeat.
     */
    public function registerDelivery(ReportDeliveryNotice $notice): string
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->withHeader('Idempotency-Key', $notice->reportKey)
            ->post('/v1/report-deliveries', $notice->toArray()));

        $id = $response->throw()->json('id');

        if (! is_string($id) || $id === '') {
            throw new UnexpectedValueException('The partner accepted the delivery but returned no id.');
        }

        return $id;
    }

    /**
     * Sends the request with the cached token. A 401 means the token was
     * revoked or expired early: get a new one and try exactly once more, so
     * a real credentials problem fails instead of looping.
     *
     * @param  Closure(PendingRequest): Response  $call
     */
    private function send(Closure $call): Response
    {
        $token = $this->tokens->token();
        $response = $call($this->request($token));

        if ($response->status() !== 401) {
            return $response;
        }

        $this->tokens->forget($token);

        return $call($this->request($this->tokens->token()));
    }

    private function request(AccessToken $token): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->withToken($token->value);
    }
}

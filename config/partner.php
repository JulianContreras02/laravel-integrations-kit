<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Partner API
    |--------------------------------------------------------------------------
    |
    | The fulfillment partner's REST API. Every call carries a bearer token
    | obtained with the OAuth2 client credentials grant (see "oauth").
    |
    */

    'api' => [
        'base_url' => env('PARTNER_API_URL'),
        'timeout_seconds' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth2 Client Credentials
    |--------------------------------------------------------------------------
    |
    | The token is cached in a store shared by every worker and refreshed
    | under an atomic lock, so a burst of jobs asks for one token, not one
    | token per job.
    |
    */

    'oauth' => [
        'token_url' => env('PARTNER_OAUTH_TOKEN_URL'),
        'client_id' => env('PARTNER_OAUTH_CLIENT_ID'),
        'client_secret' => env('PARTNER_OAUTH_CLIENT_SECRET'),
        'scope' => env('PARTNER_OAUTH_SCOPE', 'reports:write'),
        'timeout_seconds' => 5,

        // Cache store for the token and its refresh lock. Null means the
        // default store. It must support atomic locks (file, database, redis,
        // dynamodb, ...) and be shared by every process that calls the API.
        'cache_store' => env('PARTNER_OAUTH_CACHE_STORE'),

        // Treat the token as expired this many seconds early, to absorb clock
        // skew and requests that are already in flight when it expires.
        'expiry_margin_seconds' => 60,

        // How long one worker may hold the refresh lock. It must be longer
        // than the token request timeout, or a slow refresh could run twice.
        'lock_seconds' => 15,

        // How long the other workers wait for that refresh before failing.
        'lock_wait_seconds' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | SFTP Delivery
    |--------------------------------------------------------------------------
    |
    | Short in-process retries absorb network blips (a reset connection, a
    | busy server). Longer outages are left to the queue, which retries the
    | whole job minutes later and resumes from the last completed step.
    |
    */

    'sftp' => [
        'disk' => 'partner_sftp',
        // Pause before each retry, in milliseconds; two entries = three attempts.
        'backoff_ms' => [1_000, 5_000],
    ],

];

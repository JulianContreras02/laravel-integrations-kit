<?php

declare(strict_types=1);

namespace App\Integrations\Partner\OAuth;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class TokenRequestFailed extends RuntimeException
{
    public static function rejected(Response $response): self
    {
        $error = $response->json('error');

        return new self(sprintf(
            'The partner refused to issue an access token (HTTP %d%s).',
            $response->status(),
            is_string($error) ? ', '.$error : '',
        ));
    }

    public static function malformed(Response $response): self
    {
        return new self(sprintf(
            'The partner token endpoint answered HTTP %d without a usable access_token.',
            $response->status(),
        ));
    }
}

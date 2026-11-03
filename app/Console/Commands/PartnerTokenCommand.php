<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Integrations\Partner\OAuth\ClientCredentialsTokenProvider;
use Illuminate\Console\Command;

/**
 * Connectivity check for the partner's OAuth server: "works in Postman but
 * not in production" is usually networking (egress, IP allow lists), and this
 * reproduces the exact request the workers make, from where they run.
 */
final class PartnerTokenCommand extends Command
{
    protected $signature = 'partner:token';

    protected $description = 'Get (or reuse) the partner OAuth token and print its fingerprint';

    public function handle(ClientCredentialsTokenProvider $tokens): int
    {
        $token = $tokens->token();

        $this->line(sprintf(
            'fingerprint=%s expires_at=%s',
            $token->fingerprint(),
            $token->expiresAt->toIso8601String(),
        ));

        return self::SUCCESS;
    }
}

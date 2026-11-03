<?php

declare(strict_types=1);

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * The real race, with real processes and no Docker: ten artisan processes
 * start at the same moment with an empty shared cache, and each one needs a
 * token. A tiny local token server answers slowly and records every request.
 * With the refresh lock, the partner must have issued exactly one token.
 */

beforeEach(function () {
    $this->workDir = sys_get_temp_dir().'/token-race-'.Str::random(8);
    File::ensureDirectoryExists($this->workDir);

    $this->hitsFile = $this->workDir.'/hits.log';
    touch($this->hitsFile);

    $this->port = freeTcpPort();
    $this->server = Process::env(['TOKEN_SERVER_HITS' => $this->hitsFile])
        ->start([PHP_BINARY, '-S', '127.0.0.1:'.$this->port, base_path('tests/Fixtures/token-server.php')]);

    waitForTcpPort($this->port);
});

afterEach(function () {
    $this->server->stop();
    File::deleteDirectory($this->workDir);
});

it('issues a single token when ten workers need one at the same moment', function () {
    $results = Process::pool(function (Pool $pool): void {
        foreach (range(1, 10) as $worker) {
            $pool->as("worker-{$worker}")
                ->path(base_path())
                ->env([
                    'CACHE_STORE' => 'file',
                    'CACHE_FILE_PATH' => $this->workDir.'/cache',
                    'CACHE_FILE_LOCK_PATH' => $this->workDir.'/locks',
                    'PARTNER_OAUTH_CACHE_STORE' => 'file',
                    'PARTNER_OAUTH_TOKEN_URL' => 'http://127.0.0.1:'.$this->port.'/oauth/token',
                    'PARTNER_OAUTH_CLIENT_ID' => 'race-client',
                    'PARTNER_OAUTH_CLIENT_SECRET' => 'race-secret',
                ])
                ->command([PHP_BINARY, 'artisan', 'partner:token']);
        }
    })->start()->wait();

    $fingerprints = $results->collect()->map(function ($result): string {
        expect($result->successful())->toBeTrue($result->errorOutput());

        return Str::between($result->output(), 'fingerprint=', ' ');
    });

    expect(file($this->hitsFile))->toHaveCount(1)
        ->and($fingerprints)->toHaveCount(10)
        ->and($fingerprints->unique())->toHaveCount(1);
});

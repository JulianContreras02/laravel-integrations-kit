<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the application against an in-memory SQLite database
| and Laravel's fakes (HTTP, storage, mail), so they run without Docker.
| A stray real HTTP request fails the test instead of leaving the machine.
| Integration tests talk to the docker compose services instead.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('integration')
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Body of an OAuth2 token response.
 *
 * @return array<string, int|string>
 */
function tokenResponse(string $token, ?int $expiresIn = 3600): array
{
    return array_filter([
        'access_token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => $expiresIn,
    ]);
}

/**
 * Every row of the first sheet of an XLSX file, as plain PHP values.
 *
 * @return list<list<mixed>>
 */
function readSpreadsheet(string $path): array
{
    $reader = new Reader;
    $reader->open($path);

    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        break;
    }

    $reader->close();

    return $rows;
}

function freeTcpPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) Str::afterLast($address, ':');
}

function waitForTcpPort(int $port, float $timeoutSeconds = 5.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $connection = @fsockopen('127.0.0.1', $port, timeout: 0.1);

        if ($connection !== false) {
            fclose($connection);

            return;
        }

        usleep(50_000);
    }

    throw new RuntimeException("Nothing is listening on 127.0.0.1:{$port}.");
}

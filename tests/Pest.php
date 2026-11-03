<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
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

/**
 * WireMock's admin API, which plays the partner in integration tests.
 */
function wiremock(): PendingRequest
{
    return Http::baseUrl(config()->string('partner.api.base_url').'/__admin')->acceptJson()->asJson();
}

/**
 * How many requests matching $pattern WireMock has received so far.
 *
 * @param  array<string, mixed>  $pattern
 */
function wiremockCount(array $pattern): int
{
    return (int) wiremock()->post('/requests/count', $pattern)->throw()->json('count');
}

/**
 * Mailpit's API, which catches the emails sent in integration tests.
 */
function mailpit(): PendingRequest
{
    return Http::baseUrl(((string) env('MAILPIT_API_URL', 'http://mailpit:8025')).'/api/v1')->acceptJson();
}

/**
 * The latest email sent to $recipient, waiting briefly for Mailpit to store it.
 *
 * @return array<string, mixed>
 */
function latestEmailTo(string $recipient): array
{
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $summary = mailpit()->get('/search', ['query' => "to:{$recipient}"])->throw()->json('messages.0');

        if (is_array($summary)) {
            return mailpit()->get('/message/'.$summary['ID'])->throw()->json();
        }

        usleep(100_000);
    }

    throw new RuntimeException("No email reached {$recipient}.");
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

<?php

declare(strict_types=1);

use App\Jobs\DeliverDailyOrdersReport;
use App\Mail\DailyOrdersReportMail;
use App\Models\Order;
use App\Models\ReportDelivery;
use App\Models\ReportDeliveryStatus;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use League\Flysystem\UnableToWriteFile;

beforeEach(function () {
    config()->set('reports.timezone', 'America/Bogota');
    config()->set('reports.disk', 'reports');
    config()->set('reports.mail.to', ['ops@partner.example']);
    config()->set('partner.sftp.disk', 'partner_sftp');
    config()->set('partner.api.base_url', 'https://partner.test');
    config()->set('partner.oauth.token_url', 'https://partner.test/oauth/token');
    config()->set('partner.oauth.client_id', 'test-client');
    config()->set('partner.oauth.client_secret', 'test-secret');

    Storage::fake('reports');
    Storage::fake('partner_sftp');
    Mail::fake();
    Sleep::fake();
    Http::fake([
        'partner.test/oauth/token' => tokenResponse('pipeline-token'),
        'partner.test/v1/report-deliveries' => Http::response(['id' => 'dlv_1'], 201),
    ]);

    Order::factory()->count(3)->create(['placed_at' => '2026-11-02 15:00:00']);
});

/**
 * One attempt as a queue worker would run it: an exception propagates and
 * the delivery stays pending for the next attempt.
 */
function attemptDelivery(string $date = '2026-11-02'): void
{
    app()->call([new DeliverDailyOrdersReport($date), 'handle']);
}

function failingSftp(): Filesystem
{
    $remote = Mockery::mock(Filesystem::class);
    $remote->shouldReceive('exists')->andReturnFalse();
    $remote->shouldReceive('writeStream')->andThrow(UnableToWriteFile::atLocation('daily-orders-2026-11-02.xlsx.part', 'Connection timed out'));

    return $remote;
}

it('exports, uploads, registers and emails the report, recording every step', function () {
    attemptDelivery();

    $delivery = ReportDelivery::sole();
    expect($delivery->report_key)->toBe('daily-orders:2026-11-02')
        ->and($delivery->status)->toBe(ReportDeliveryStatus::Completed)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->row_count)->toBe(3)
        ->and($delivery->partner_delivery_id)->toBe('dlv_1')
        ->and($delivery->emailed_as_attachment)->toBeTrue()
        ->and($delivery->exported_at)->not->toBeNull()
        ->and($delivery->sftp_delivered_at)->not->toBeNull()
        ->and($delivery->partner_notified_at)->not->toBeNull()
        ->and($delivery->emailed_at)->not->toBeNull()
        ->and($delivery->last_error)->toBeNull();

    $remoteBytes = Storage::disk('partner_sftp')->get('daily-orders-2026-11-02.xlsx');
    expect(hash('sha256', (string) $remoteBytes))->toBe($delivery->sha256);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/report-deliveries')
        && $request['sha256'] === $delivery->sha256
        && $request['row_count'] === 3);
    Mail::assertSent(DailyOrdersReportMail::class, 1);
});

it('delivers once when the job runs twice', function () {
    attemptDelivery();
    attemptDelivery();

    expect(ReportDelivery::sole()->attempts)->toBe(1)
        ->and(Storage::disk('partner_sftp')->allFiles())->toBe(['daily-orders-2026-11-02.xlsx']);
    Http::assertSentCount(2); // one token, one registration
    Mail::assertSentCount(1);
});

it('resumes after a failure without repeating the steps that already succeeded', function () {
    Storage::set('partner_sftp', failingSftp());

    expect(fn () => attemptDelivery())->toThrow(UnableToWriteFile::class);

    $afterFailure = ReportDelivery::sole();
    expect($afterFailure->status)->toBe(ReportDeliveryStatus::Pending)
        ->and($afterFailure->exported_at)->not->toBeNull()
        ->and($afterFailure->sftp_delivered_at)->toBeNull()
        ->and($afterFailure->last_error)->toContain('UnableToWriteFile');
    Http::assertNothingSent();
    Mail::assertNothingSent();

    // The SFTP server is back when the queue retries the job.
    Storage::fake('partner_sftp');
    $this->travel(5)->minutes();
    attemptDelivery();

    $afterRetry = ReportDelivery::sole();
    expect($afterRetry->status)->toBe(ReportDeliveryStatus::Completed)
        ->and($afterRetry->attempts)->toBe(2)
        ->and($afterRetry->exported_at->equalTo($afterFailure->exported_at))->toBeTrue()
        ->and($afterRetry->sha256)->toBe($afterFailure->sha256)
        ->and($afterRetry->last_error)->toBeNull();
});

it('stops at once, without retries, when the partner already has a different file', function () {
    Storage::disk('partner_sftp')->put('daily-orders-2026-11-02.xlsx', 'a different file');

    DeliverDailyOrdersReport::dispatchSync('2026-11-02');

    $delivery = ReportDelivery::sole();
    expect($delivery->status)->toBe(ReportDeliveryStatus::Failed)
        ->and($delivery->last_error)->toContain('RemoteFileConflict')
        ->and(Storage::disk('partner_sftp')->get('daily-orders-2026-11-02.xlsx'))->toBe('a different file');
    Sleep::assertNeverSlept();
    Mail::assertNothingSent();
});

it('never lets two workers deliver the same report at the same time', function () {
    $middleware = (new DeliverDailyOrdersReport('2026-11-02'))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0]->key)->toBe('daily-orders:2026-11-02');
});

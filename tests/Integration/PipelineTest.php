<?php

declare(strict_types=1);

use App\Jobs\DeliverDailyOrdersReport;
use App\Models\Order;
use App\Models\ReportDelivery;
use App\Models\ReportDeliveryStatus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

const PIPELINE_DATE = '2031-01-15';
const PIPELINE_REMOTE_FILE = 'daily-orders-2031-01-15.xlsx';

beforeEach(function () {
    $this->runId = Str::lower(Str::random(8));
    $this->recipient = "it-{$this->runId}@partner.example";

    config()->set('mail.default', 'smtp');
    config()->set('reports.timezone', 'UTC');
    config()->set('reports.directory', "integration-tests/{$this->runId}");
    config()->set('reports.mail.to', [$this->recipient]);

    Storage::disk('partner_sftp')->delete(PIPELINE_REMOTE_FILE);
});

afterEach(function () {
    Storage::disk('reports')->deleteDirectory("integration-tests/{$this->runId}");
    Storage::disk('partner_sftp')->delete(PIPELINE_REMOTE_FILE);
});

it('delivers a business day through every real service, once', function () {
    Order::factory()->count(20)->create(['placed_at' => PIPELINE_DATE.' 15:00:00']);
    $registrations = [
        'method' => 'POST',
        'url' => '/v1/report-deliveries',
        'headers' => ['Idempotency-Key' => ['equalTo' => 'daily-orders:'.PIPELINE_DATE]],
    ];
    $registrationsBefore = wiremockCount($registrations);

    app()->call([new DeliverDailyOrdersReport(PIPELINE_DATE), 'handle']);
    app()->call([new DeliverDailyOrdersReport(PIPELINE_DATE), 'handle']);

    $delivery = ReportDelivery::sole();
    expect($delivery->status)->toBe(ReportDeliveryStatus::Completed)
        ->and($delivery->row_count)->toBe(20)
        ->and(Str::isUuid((string) $delivery->partner_delivery_id))->toBeTrue();

    $remoteBytes = (string) Storage::disk('partner_sftp')->get(PIPELINE_REMOTE_FILE);
    expect(hash('sha256', $remoteBytes))->toBe($delivery->sha256)
        ->and(wiremockCount($registrations) - $registrationsBefore)->toBe(1)
        ->and(latestEmailTo($this->recipient)['Attachments'])->toHaveCount(1);
});

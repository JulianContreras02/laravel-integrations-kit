<?php

declare(strict_types=1);

use App\Jobs\DeliverDailyOrdersReport;
use App\Models\ReportDelivery;
use App\Models\ReportDeliveryStatus;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('reports.timezone', 'America/Bogota');
    config()->set('reports.catch_up_days', 3);
    Queue::fake();
});

function settledDelivery(string $date, ReportDeliveryStatus $status): ReportDelivery
{
    return ReportDelivery::create([
        'report_key' => DeliverDailyOrdersReport::reportKey($date),
        'report_date' => $date,
        'status' => $status,
    ]);
}

it('queues every day of the catch-up window that has not been delivered', function () {
    $this->travelTo('2026-11-05 10:00:00');
    settledDelivery('2026-11-03', ReportDeliveryStatus::Completed);

    $this->artisan('report:deliver')
        ->expectsOutput('Queued 2026-11-02.')
        ->expectsOutput('Queued 2026-11-04.')
        ->assertSuccessful();

    Queue::assertPushed(DeliverDailyOrdersReport::class, 2);
    Queue::assertPushed(DeliverDailyOrdersReport::class, fn ($job): bool => $job->reportDate === '2026-11-02');
    Queue::assertPushed(DeliverDailyOrdersReport::class, fn ($job): bool => $job->reportDate === '2026-11-04');
});

it('leaves failed days for a person to re-run', function () {
    $this->travelTo('2026-11-05 10:00:00');
    settledDelivery('2026-11-02', ReportDeliveryStatus::Failed);
    settledDelivery('2026-11-03', ReportDeliveryStatus::Completed);
    settledDelivery('2026-11-04', ReportDeliveryStatus::Completed);

    $this->artisan('report:deliver')
        ->expectsOutputToContain('Skipping daily-orders:2026-11-02')
        ->expectsOutput('Nothing to deliver: every day in the window is settled.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('works out yesterday in the shop timezone, not in UTC', function () {
    config()->set('reports.catch_up_days', 1);
    // 01:00 UTC on Nov 3 is still 20:00 on Nov 2 in Bogotá: yesterday is Nov 1.
    $this->travelTo(now('UTC')->setDateTime(2026, 11, 3, 1, 0));

    $this->artisan('report:deliver')->assertSuccessful();

    Queue::assertPushed(DeliverDailyOrdersReport::class, fn ($job): bool => $job->reportDate === '2026-11-01');
});

it('re-runs a failed day on request', function () {
    $this->travelTo('2026-11-05 10:00:00');
    $failed = settledDelivery('2026-11-02', ReportDeliveryStatus::Failed);

    $this->artisan('report:deliver', ['--date' => '2026-11-02'])->assertSuccessful();

    expect($failed->fresh()?->status)->toBe(ReportDeliveryStatus::Pending);
    Queue::assertPushed(DeliverDailyOrdersReport::class, fn ($job): bool => $job->reportDate === '2026-11-02');
});

it('refuses a day that is not over yet', function () {
    $this->travelTo('2026-11-05 10:00:00');

    $this->artisan('report:deliver', ['--date' => '2026-11-05'])
        ->expectsOutputToContain('is not over yet')
        ->assertFailed();

    Queue::assertNothingPushed();
});

it('rejects an impossible date', function () {
    $this->artisan('report:deliver', ['--date' => '2026-02-30'])
        ->expectsOutputToContain('Invalid date')
        ->assertFailed();
});

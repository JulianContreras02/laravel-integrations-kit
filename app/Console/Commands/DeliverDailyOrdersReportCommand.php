<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverDailyOrdersReport;
use App\Models\ReportDelivery;
use App\Models\ReportDeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Without --date it catches up: every day of the last N days that has no
 * completed delivery is queued, so if the scheduler was down over the
 * weekend, Monday's run delivers Saturday and Sunday too. Days older than
 * the window, and days that failed, are left for a person to run by hand.
 */
final class DeliverDailyOrdersReportCommand extends Command
{
    protected $signature = 'report:deliver
        {--date= : Deliver this business day (YYYY-MM-DD), even if it failed before}
        {--sync : Run in this process instead of queueing (no queue retries)}';

    protected $description = 'Deliver the daily orders report to the partner';

    public function handle(): int
    {
        $timezone = Config::string('reports.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $date = $this->option('date');

        if (is_string($date)) {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);

            if (! $day instanceof CarbonImmutable || $day->toDateString() !== $date) {
                $this->error("Invalid date [{$date}], expected YYYY-MM-DD.");

                return self::INVALID;
            }

            if ($day->greaterThanOrEqualTo($today)) {
                $this->error("{$date} is not over yet in {$timezone}.");

                return self::INVALID;
            }

            $this->retryIfFailed($date);
            $this->deliver([$date]);

            return self::SUCCESS;
        }

        $this->deliver($this->pendingDays($today, Config::integer('reports.catch_up_days')));

        return self::SUCCESS;
    }

    /**
     * Days in the catch-up window without a completed or failed delivery.
     *
     * @return list<string>
     */
    private function pendingDays(CarbonImmutable $today, int $window): array
    {
        $candidates = [];

        for ($daysAgo = $window; $daysAgo >= 1; $daysAgo--) {
            $candidates[] = $today->subDays($daysAgo)->toDateString();
        }

        $settled = ReportDelivery::query()
            ->whereIn('report_key', array_map(DeliverDailyOrdersReport::reportKey(...), $candidates))
            ->whereIn('status', [ReportDeliveryStatus::Completed, ReportDeliveryStatus::Failed])
            ->get(['report_key', 'status']);

        foreach ($settled->where('status', ReportDeliveryStatus::Failed) as $failed) {
            $this->warn("Skipping {$failed->report_key}: it failed and needs a manual re-run with --date.");
        }

        $settledKeys = $settled->pluck('report_key')->all();

        return array_values(array_filter(
            $candidates,
            fn (string $date): bool => ! in_array(DeliverDailyOrdersReport::reportKey($date), $settledKeys, true),
        ));
    }

    private function retryIfFailed(string $date): void
    {
        ReportDelivery::query()
            ->where('report_key', DeliverDailyOrdersReport::reportKey($date))
            ->where('status', ReportDeliveryStatus::Failed)
            ->update(['status' => ReportDeliveryStatus::Pending]);
    }

    /**
     * @param  list<string>  $dates
     */
    private function deliver(array $dates): void
    {
        if ($dates === []) {
            $this->info('Nothing to deliver: every day in the window is settled.');

            return;
        }

        foreach ($dates as $date) {
            if ($this->option('sync')) {
                DeliverDailyOrdersReport::dispatchSync($date);
                $this->info("Delivered {$date}.");
            } else {
                DeliverDailyOrdersReport::dispatch($date);
                $this->info("Queued {$date}.");
            }
        }
    }
}

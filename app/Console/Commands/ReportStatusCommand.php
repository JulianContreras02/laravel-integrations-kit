<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ReportDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ReportStatusCommand extends Command
{
    protected $signature = 'report:status {--limit=10 : How many deliveries to show, most recently active first}';

    protected $description = 'Show the most recently active deliveries and how far each one got';

    public function handle(): int
    {
        $deliveries = ReportDelivery::query()
            ->latest('updated_at')
            ->latest('report_date')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($deliveries->isEmpty()) {
            $this->info('No deliveries yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Day', 'Status', 'Orders', 'Exported', 'SFTP', 'Partner API', 'Email', 'Attempts', 'Last error'],
            $deliveries->map(fn (ReportDelivery $delivery): array => [
                $delivery->report_date->toDateString(),
                $delivery->status->value,
                $delivery->row_count === null ? '-' : number_format($delivery->row_count),
                self::when($delivery->exported_at),
                self::when($delivery->sftp_delivered_at),
                self::when($delivery->partner_notified_at),
                $delivery->emailed_at === null ? '-' : self::when($delivery->emailed_at).($delivery->emailed_as_attachment === true ? ' (attached)' : ' (link)'),
                $delivery->attempts,
                Str::limit((string) $delivery->last_error, 60),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private static function when(?CarbonImmutable $moment): string
    {
        return $moment?->format('H:i:s') ?? '-';
    }
}

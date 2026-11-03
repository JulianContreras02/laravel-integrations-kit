<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\RemoteFileConflict;
use App\Delivery\SftpDelivery;
use App\Integrations\Partner\PartnerApiClient;
use App\Integrations\Partner\ReportDeliveryNotice;
use App\Mail\ReportMailer;
use App\Models\ReportDelivery;
use App\Models\ReportDeliveryStatus;
use App\Reports\DailyOrdersExporter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Throwable;

/**
 * Exports one business day, delivers it over SFTP, registers it with the
 * partner's API and emails it, recording each step in the control table.
 *
 * A retry resumes after the last completed step, so a failure in step 3
 * never regenerates or re-uploads the file. Steps that talk to the partner
 * are idempotent on their side too (SFTP existence check, Idempotency-Key);
 * the email is at-least-once: a crash right after sending it means it is
 * sent again on retry.
 */
final class DeliverDailyOrdersReport implements ShouldQueue
{
    use Queueable;

    /**
     * Queue-level retries cover outages that last minutes; the SFTP step
     * already retries short network blips in-process.
     */
    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 900;

    public function __construct(public readonly string $reportDate) {}

    public static function reportKey(string $reportDate): string
    {
        return 'daily-orders:'.$reportDate;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // Two workers must never deliver the same report at the same time.
        return [
            (new WithoutOverlapping(self::reportKey($this->reportDate)))
                ->releaseAfter(60)
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(DailyOrdersExporter $exporter, SftpDelivery $sftp, PartnerApiClient $partner, ReportMailer $mailer): void
    {
        $day = CarbonImmutable::parse($this->reportDate);

        $delivery = ReportDelivery::createOrFirst(
            ['report_key' => self::reportKey($this->reportDate)],
            ['report_date' => $this->reportDate],
        );

        if ($delivery->status === ReportDeliveryStatus::Completed) {
            return;
        }

        $delivery->increment('attempts');

        try {
            if ($delivery->exported_at === null) {
                $delivery->recordExport($exporter->export($day));
            }

            $report = $delivery->exportedReport();

            if ($delivery->sftp_delivered_at === null) {
                $sftp->deliver(Config::string('reports.disk'), $report->path, $report->fileName);
                $delivery->update(['sftp_delivered_at' => CarbonImmutable::now()]);
            }

            if ($delivery->partner_notified_at === null) {
                $partnerId = $partner->registerDelivery(new ReportDeliveryNotice(
                    reportKey: $delivery->report_key,
                    fileName: $report->fileName,
                    sha256: $report->sha256,
                    sizeBytes: $report->sizeBytes,
                    rowCount: $report->rowCount,
                    deliveredAt: $delivery->sftp_delivered_at ?? CarbonImmutable::now(),
                ));
                $delivery->update(['partner_delivery_id' => $partnerId, 'partner_notified_at' => CarbonImmutable::now()]);
            }

            if ($delivery->emailed_at === null) {
                $attached = $mailer->send($day, $report);
                $delivery->update(['emailed_as_attachment' => $attached, 'emailed_at' => CarbonImmutable::now()]);
            }

            $delivery->update([
                'status' => ReportDeliveryStatus::Completed,
                'completed_at' => CarbonImmutable::now(),
                'last_error' => null,
            ]);
        } catch (RemoteFileConflict $conflict) {
            // Retrying cannot fix this; stop now instead of burning retries.
            $delivery->update(['last_error' => self::describe($conflict)]);
            $this->fail($conflict);
        } catch (Throwable $exception) {
            $delivery->update(['last_error' => self::describe($exception)]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ReportDelivery::query()
            ->where('report_key', self::reportKey($this->reportDate))
            ->update(['status' => ReportDeliveryStatus::Failed]);
    }

    private static function describe(Throwable $exception): string
    {
        return Str::limit(class_basename($exception).': '.$exception->getMessage(), 1_000);
    }
}

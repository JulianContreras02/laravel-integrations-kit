<?php

declare(strict_types=1);

namespace App\Mail;

use App\Reports\ExportedReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Facades\Mail;

final class ReportMailer
{
    /**
     * @param  list<string>  $recipients
     */
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly AttachmentPolicy $policy,
        private readonly array $recipients,
        private readonly string $timezone,
        private readonly string $disk,
        private readonly int $linkTtlDays,
    ) {}

    /**
     * Emails the report, attached when it fits and as a signed link when it
     * does not. Returns whether it was attached.
     */
    public function send(CarbonImmutable $day, ExportedReport $report): bool
    {
        $attached = $this->policy->fits($report->sizeBytes);

        if ($attached) {
            $mail = DailyOrdersReportMail::forAttachment($day, $this->timezone, $report, $this->disk);
        } else {
            $expiresAt = CarbonImmutable::now()->addDays($this->linkTtlDays);
            $link = $this->url->temporarySignedRoute('reports.daily-orders.download', $expiresAt, ['date' => $day->toDateString()]);
            $mail = DailyOrdersReportMail::forDownloadLink($day, $this->timezone, $report, $this->disk, $link, $expiresAt);
        }

        Mail::to($this->recipients)->send($mail);

        return $attached;
    }
}

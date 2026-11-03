<?php

declare(strict_types=1);

namespace App\Mail;

use App\Reports\DailyOrdersExporter;
use App\Reports\ExportedReport;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class DailyOrdersReportMail extends Mailable
{
    /**
     * Use forAttachment() or forDownloadLink() instead.
     */
    private function __construct(
        public readonly string $reportDate,
        public readonly string $timezone,
        public readonly ExportedReport $report,
        public readonly string $disk,
        public readonly ?string $downloadUrl = null,
        public readonly ?CarbonImmutable $linkExpiresAt = null,
    ) {}

    public static function forAttachment(CarbonImmutable $day, string $timezone, ExportedReport $report, string $disk): self
    {
        return new self($day->toDateString(), $timezone, $report, $disk);
    }

    public static function forDownloadLink(CarbonImmutable $day, string $timezone, ExportedReport $report, string $disk, string $downloadUrl, CarbonImmutable $expiresAt): self
    {
        return new self($day->toDateString(), $timezone, $report, $disk, $downloadUrl, $expiresAt);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Daily orders report %s (%s orders)', $this->reportDate, number_format($this->report->rowCount)),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-orders-report');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->downloadUrl !== null) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($this->disk, $this->report->path)
                ->as($this->report->fileName)
                ->withMime(DailyOrdersExporter::MIME_TYPE),
        ];
    }
}

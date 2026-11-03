<?php

declare(strict_types=1);

use App\Mail\DailyOrdersReportMail;
use App\Mail\ReportMailer;
use App\Reports\DailyOrdersExporter;
use App\Reports\ExportedReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config()->set('reports.timezone', 'America/Bogota');
    config()->set('reports.disk', 'reports');
    config()->set('reports.mail.to', ['ops@partner.example', 'reports@shop.example']);
    config()->set('reports.mail.max_message_bytes', 10_000_000);
    config()->set('reports.mail.link_ttl_days', 7);

    Storage::fake('reports');
    Mail::fake();
});

function storedReport(int $sizeBytes): ExportedReport
{
    $path = 'daily-orders/2026/11/daily-orders-2026-11-02.xlsx';
    Storage::disk('reports')->put($path, str_repeat('x', $sizeBytes));

    return new ExportedReport($path, 'daily-orders-2026-11-02.xlsx', 1_500, $sizeBytes, str_repeat('a', 64));
}

it('attaches a report that fits in the message', function () {
    $report = storedReport(200_000);

    $attached = app(ReportMailer::class)->send(CarbonImmutable::parse('2026-11-02'), $report);

    expect($attached)->toBeTrue();
    Mail::assertSent(DailyOrdersReportMail::class, function (DailyOrdersReportMail $mail) use ($report): bool {
        $mail->assertHasAttachment(Attachment::fromStorageDisk('reports', $report->path)
            ->as('daily-orders-2026-11-02.xlsx')
            ->withMime(DailyOrdersExporter::MIME_TYPE));
        $mail->assertSeeInHtml('The file is attached');
        $mail->assertDontSeeInHtml('Download the report');

        return $mail->hasTo('ops@partner.example') && $mail->hasTo('reports@shop.example');
    });
});

it('sends a signed link instead when the report would exceed the size limit', function () {
    config()->set('reports.mail.max_message_bytes', 500_000);
    $report = storedReport(400_000); // about 547 KB once base64-encoded

    $attached = app(ReportMailer::class)->send(CarbonImmutable::parse('2026-11-02'), $report);

    expect($attached)->toBeFalse();
    Mail::assertSent(DailyOrdersReportMail::class, function (DailyOrdersReportMail $mail): bool {
        expect($mail->attachments())->toBeEmpty()
            ->and($mail->downloadUrl)->toStartWith(url('/reports/daily-orders/2026-11-02'))
            ->and(URL::hasValidSignature(Request::create((string) $mail->downloadUrl)))->toBeTrue()
            ->and($mail->linkExpiresAt?->toDateString())->toBe(now()->addDays(7)->toDateString());

        $mail->assertSeeInHtml('Download the report');
        $mail->assertSeeInHtml((string) $mail->downloadUrl, escape: true);

        return true;
    });
});

it('puts the date and the number of orders in the subject', function () {
    app(ReportMailer::class)->send(CarbonImmutable::parse('2026-11-02'), storedReport(1_000));

    Mail::assertSent(DailyOrdersReportMail::class, fn (DailyOrdersReportMail $mail): bool => $mail->hasSubject('Daily orders report 2026-11-02 (1,500 orders)'));
});

<?php

declare(strict_types=1);

use App\Mail\ReportMailer;
use App\Reports\ExportedReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->runId = Str::lower(Str::random(8));
    $this->recipient = "it-{$this->runId}@partner.example";

    config()->set('mail.default', 'smtp');
    config()->set('reports.mail.to', [$this->recipient]);

    $this->path = "integration-tests/{$this->runId}/daily-orders-2031-01-15.xlsx";
    $bytes = random_bytes(60_000);
    Storage::disk('reports')->put($this->path, $bytes);
    $this->report = new ExportedReport($this->path, 'daily-orders-2031-01-15.xlsx', 20, strlen($bytes), hash('sha256', $bytes));
});

afterEach(fn () => Storage::disk('reports')->deleteDirectory("integration-tests/{$this->runId}"));

it('sends a report that fits as an attachment over SMTP', function () {
    app(ReportMailer::class)->send(CarbonImmutable::parse('2031-01-15'), $this->report);

    $email = latestEmailTo($this->recipient);
    expect($email['Subject'])->toBe('Daily orders report 2031-01-15 (20 orders)')
        ->and($email['Attachments'])->toHaveCount(1)
        ->and($email['Attachments'][0]['FileName'])->toBe('daily-orders-2031-01-15.xlsx')
        ->and($email['Attachments'][0]['Size'])->toBe(60_000);
});

it('sends a signed link when the report would make the message too big', function () {
    config()->set('reports.mail.max_message_bytes', 50_000);

    app(ReportMailer::class)->send(CarbonImmutable::parse('2031-01-15'), $this->report);

    $email = latestEmailTo($this->recipient);
    expect($email['Attachments'])->toBeEmpty()
        ->and($email['Text'])->toContain('/reports/daily-orders/2031-01-15?expires=')
        ->and($email['Text'])->toContain('&signature=');
});

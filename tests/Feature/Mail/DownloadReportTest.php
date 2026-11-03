<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

const DOWNLOAD_PATH = 'daily-orders/2026/11/daily-orders-2026-11-02.xlsx';

beforeEach(function () {
    config()->set('reports.disk', 'reports');
    config()->set('reports.signing_disk', 'reports_public');
    config()->set('reports.mail.presigned_url_ttl_minutes', 5);

    Storage::fake('reports');
    Storage::fake('reports_public');
    Storage::disk('reports')->put(DOWNLOAD_PATH, 'spreadsheet bytes');

    Storage::disk('reports_public')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expiresAt, array $options): string => 'https://s3.test/'.$path
            .'?expires='.$expiresAt->getTimestamp()
            .'&disposition='.rawurlencode($options['ResponseContentDisposition']),
    );
});

function downloadLink(string $date = '2026-11-02', int $days = 7): string
{
    return URL::temporarySignedRoute('reports.daily-orders.download', now()->addDays($days), ['date' => $date]);
}

it('redirects a valid link to a short-lived presigned S3 URL', function () {
    $response = $this->get(downloadLink());

    $expectedExpiry = CarbonImmutable::now()->addMinutes(5)->getTimestamp();
    $response->assertRedirect('https://s3.test/'.DOWNLOAD_PATH
        .'?expires='.$expectedExpiry
        .'&disposition='.rawurlencode('attachment; filename="daily-orders-2026-11-02.xlsx"'));
});

it('rejects a link whose date was changed', function () {
    $tampered = str_replace('2026-11-02', '2026-11-01', downloadLink());

    $this->get($tampered)->assertForbidden();
});

it('rejects an expired link', function () {
    $link = downloadLink(days: 7);

    $this->travel(7)->days();
    $this->travel(1)->minutes();

    $this->get($link)->assertForbidden();
});

it('rejects a link without a signature', function () {
    $this->get('/reports/daily-orders/2026-11-02')->assertForbidden();
});

it('answers 404 when the report no longer exists', function () {
    Storage::disk('reports')->delete(DOWNLOAD_PATH);

    $this->get(downloadLink())->assertNotFound();
});

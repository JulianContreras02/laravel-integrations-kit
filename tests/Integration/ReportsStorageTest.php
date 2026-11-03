<?php

declare(strict_types=1);

use App\Models\Order;
use App\Reports\DailyOrdersExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->directory = 'integration-tests/'.Str::lower(Str::random(8));
    config()->set('reports.directory', $this->directory);
    config()->set('reports.timezone', 'UTC');
});

afterEach(fn () => Storage::disk('reports')->deleteDirectory($this->directory));

it('streams the export to the bucket', function () {
    Order::factory()->count(50)->create(['placed_at' => '2031-01-15 15:00:00']);

    $report = app(DailyOrdersExporter::class)->export(CarbonImmutable::parse('2031-01-15'));

    $disk = Storage::disk('reports');
    expect($disk->size($report->path))->toBe($report->sizeBytes)
        ->and(hash('sha256', (string) $disk->get($report->path)))->toBe($report->sha256)
        ->and($disk->mimeType($report->path))->toBe(DailyOrdersExporter::MIME_TYPE);
});

it('serves the file through a presigned URL that cannot be altered', function () {
    $report = app(DailyOrdersExporter::class)->export(CarbonImmutable::parse('2031-01-15'));

    // Signed for the endpoint this container reaches; the browser-facing
    // variant (reports_public) differs only in the host it signs.
    $url = Storage::disk('reports')->temporaryUrl($report->path, now()->addMinutes(5));

    $download = Http::get($url);
    expect($download->status())->toBe(200)
        ->and(hash('sha256', $download->body()))->toBe($report->sha256);

    $tampered = str_replace('X-Amz-Expires=300', 'X-Amz-Expires=3000', $url);
    expect(Http::get($tampered)->status())->toBe(403);
});

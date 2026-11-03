<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderStatus;
use App\Reports\DailyOrdersExporter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;

beforeEach(function () {
    config()->set('reports.timezone', 'America/Bogota');
    config()->set('reports.disk', 'reports');
    Storage::fake('reports');
});

it('exports the orders of the business day, in the business timezone', function () {
    // 2026-11-02 in Bogotá (UTC-5) runs from 05:00 UTC to 05:00 UTC the next day.
    Order::factory()->create(['number' => 'SO-LATE-PREVIOUS-DAY', 'placed_at' => '2026-11-02 04:59:59']);
    Order::factory()->create([
        'number' => 'SO-FIRST',
        'placed_at' => '2026-11-02 05:00:00',
        'status' => OrderStatus::Paid,
        'customer_name' => 'Ana Torres',
        'shipping_city' => 'Tunja',
        'shipping_country' => 'CO',
        'items_count' => 3,
        'total_cents' => 12_345,
        'currency' => 'USD',
    ]);
    Order::factory()->create(['number' => 'SO-LAST', 'placed_at' => '2026-11-03 04:59:59']);
    Order::factory()->create(['number' => 'SO-NEXT-DAY', 'placed_at' => '2026-11-03 05:00:00']);

    $report = app(DailyOrdersExporter::class)->export(CarbonImmutable::parse('2026-11-02'));

    expect($report->path)->toBe('daily-orders/2026/11/daily-orders-2026-11-02.xlsx')
        ->and($report->fileName)->toBe('daily-orders-2026-11-02.xlsx')
        ->and($report->rowCount)->toBe(2);
    Storage::disk('reports')->assertExists($report->path);

    $rows = readSpreadsheet(Storage::disk('reports')->path($report->path));

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(['Order number', 'Placed at (America/Bogota)', 'Status', 'Customer', 'City', 'Country', 'Items', 'Total', 'Currency'])
        ->and($rows[1][0])->toBe('SO-FIRST')
        ->and($rows[1][1]->format('Y-m-d H:i'))->toBe('2026-11-02 00:00')
        ->and(array_slice($rows[1], 2))->toBe(['paid', 'Ana Torres', 'Tunja', 'CO', 3, 123.45, 'USD'])
        ->and($rows[2][0])->toBe('SO-LAST')
        ->and($rows[2][1]->format('Y-m-d H:i'))->toBe('2026-11-02 23:59');
});

it('never sends the customer email to the partner', function () {
    Order::factory()->create(['customer_email' => 'private@shop.example', 'placed_at' => '2026-11-02 12:00:00']);

    $report = app(DailyOrdersExporter::class)->export(CarbonImmutable::parse('2026-11-02'));

    $cells = collect(readSpreadsheet(Storage::disk('reports')->path($report->path)))->flatten()->all();
    expect($cells)->not->toContain('private@shop.example');
});

it('reports the size and checksum of the stored file', function () {
    Order::factory()->count(5)->create(['placed_at' => '2026-11-02 12:00:00']);

    $report = app(DailyOrdersExporter::class)->export(CarbonImmutable::parse('2026-11-02'));
    $stored = Storage::disk('reports')->get($report->path);

    expect($report->sizeBytes)->toBe(strlen((string) $stored))
        ->and($report->sha256)->toBe(hash('sha256', (string) $stored));
});

it('still produces a file with the header when there were no orders', function () {
    $report = app(DailyOrdersExporter::class)->export(CarbonImmutable::parse('2026-11-02'));

    expect($report->rowCount)->toBe(0)
        ->and(readSpreadsheet(Storage::disk('reports')->path($report->path)))->toHaveCount(1);
});

it('leaves no temporary files behind, even when the upload fails', function () {
    $temporaryDirectory = sys_get_temp_dir().'/export-test-'.Str::random(8);
    File::ensureDirectoryExists($temporaryDirectory);
    config()->set('reports.temp_directory', $temporaryDirectory);

    $failingDisk = Mockery::mock(Filesystem::class);
    $failingDisk->shouldReceive('writeStream')->andThrow(UnableToWriteFile::atLocation('daily-orders/x.xlsx', 'S3 is down'));
    Storage::set('reports', $failingDisk);

    Order::factory()->count(3)->create(['placed_at' => '2026-11-02 12:00:00']);

    expect(fn () => app(DailyOrdersExporter::class)->export(CarbonImmutable::parse('2026-11-02')))
        ->toThrow(UnableToWriteFile::class);
    expect(File::allFiles($temporaryDirectory))->toBeEmpty();

    File::deleteDirectory($temporaryDirectory);
});

<?php

declare(strict_types=1);

use App\Reports\DailyOrdersExporter;
use Carbon\CarbonImmutable;
use Database\Seeders\SyntheticOrders;
use Illuminate\Support\Facades\Storage;

/*
 * Worst-case volume, scaled down to keep the suite fast: if memory grew with
 * the number of rows, twenty times more rows would show it clearly. The
 * benchmark command measures the real volumes (see the README).
 */

function exportPeakMemory(CarbonImmutable $day): int
{
    gc_collect_cycles();
    $baseline = memory_get_usage();
    memory_reset_peak_usage();

    app(DailyOrdersExporter::class)->export($day);

    return memory_get_peak_usage() - $baseline;
}

it('keeps memory flat when the day grows from 2,000 to 40,000 orders', function () {
    config()->set('reports.timezone', 'UTC');
    Storage::fake('reports');

    $warmUp = CarbonImmutable::parse('2026-10-31');
    $smallDay = CarbonImmutable::parse('2026-11-01');
    $largeDay = CarbonImmutable::parse('2026-11-02');

    SyntheticOrders::insertForDay($warmUp, 'UTC', 100);
    SyntheticOrders::insertForDay($smallDay, 'UTC', 2_000);
    SyntheticOrders::insertForDay($largeDay, 'UTC', 40_000);

    // The first export loads classes and warms up OpenSpout; do not count it.
    exportPeakMemory($warmUp);

    $small = exportPeakMemory($smallDay);
    $large = exportPeakMemory($largeDay);

    // Measured: about 1.1 MB for both. Loading the rows with get() instead
    // makes the large export use about 43 MB and fails this assertion.
    expect($large - $small)->toBeLessThan(2 * 1024 * 1024);
});

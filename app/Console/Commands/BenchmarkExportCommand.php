<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\AttachmentPolicy;
use App\Reports\DailyOrdersExporter;
use Carbon\CarbonImmutable;
use Database\Seeders\SyntheticOrders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Worst-case volume check before trusting the export in production: fills a
 * throwaway in-memory database with synthetic orders, exports each volume
 * to the real reports disk and reports time, PHP memory and file size.
 *
 * SQLite in memory makes the database side faster than a networked MySQL,
 * so read the time column as a lower bound. Memory and size do not depend
 * on it.
 */
final class BenchmarkExportCommand extends Command
{
    protected $signature = 'report:benchmark
        {--rows=10000,100000,300000 : Comma-separated volumes to measure}';

    protected $description = 'Measure export time, memory and file size for worst-case volumes';

    public function handle(): int
    {
        $volumes = array_map(intval(...), explode(',', (string) $this->option('rows')));
        $previousConnection = DB::getDefaultConnection();
        $previousDirectory = Config::string('reports.directory');
        $directory = 'benchmarks/'.Str::lower((string) Str::ulid());

        $this->useThrowawayDatabase();
        Config::set('reports.directory', $directory);

        try {
            $results = $this->measure($volumes, Config::string('reports.timezone'));
        } finally {
            Storage::disk(Config::string('reports.disk'))->deleteDirectory($directory);
            Config::set('reports.directory', $previousDirectory);
            DB::setDefaultConnection($previousConnection);
            DB::purge('benchmark');
        }

        $this->table(['Orders', 'Export + upload', 'PHP peak memory', 'Used by the export', 'File size', 'Email'], $results);

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $volumes
     * @return list<list<string>>
     */
    private function measure(array $volumes, string $timezone): array
    {
        $exporter = app(DailyOrdersExporter::class);
        $policy = app(AttachmentPolicy::class);
        $results = [];

        // The first export loads classes and initialises the AWS SDK and
        // OpenSpout; measuring it would blame that on the first volume.
        $exporter->export(CarbonImmutable::parse('2029-12-31'));

        foreach ($volumes as $index => $rows) {
            $day = CarbonImmutable::parse('2030-01-01')->addDays($index);
            SyntheticOrders::insertForDay($day, $timezone, $rows);

            gc_collect_cycles();
            $baseline = memory_get_usage();
            memory_reset_peak_usage();
            $started = hrtime(true);

            $report = $exporter->export($day);

            $seconds = (hrtime(true) - $started) / 1e9;
            $peak = memory_get_peak_usage();

            $results[] = [
                number_format($report->rowCount),
                sprintf('%.1f s', $seconds),
                sprintf('%.1f MB', $peak / 1_048_576),
                sprintf('%.1f MB', ($peak - $baseline) / 1_048_576),
                sprintf('%.2f MB', $report->sizeBytes / 1_048_576),
                $policy->fits($report->sizeBytes) ? 'attachment' : 'signed link',
            ];

            SyntheticOrders::delete();
        }

        return $results;
    }

    private function useThrowawayDatabase(): void
    {
        Config::set('database.connections.benchmark', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::setDefaultConnection('benchmark');

        Artisan::call('migrate', ['--database' => 'benchmark', '--force' => true]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo data: orders for the last three business days, so the catch-up
     * run has something to deliver for each of them.
     */
    public function run(): void
    {
        $timezone = Config::string('reports.timezone');
        $yesterday = CarbonImmutable::now($timezone)->subDay()->startOfDay();

        foreach ([1_500, 1_200, 900] as $daysAgo => $count) {
            SyntheticOrders::insertForDay($yesterday->subDays($daysAgo), $timezone, $count);
        }
    }
}

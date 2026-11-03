<?php

declare(strict_types=1);

use App\Models\ReportDelivery;
use App\Models\ReportDeliveryStatus;

it('shows how far each delivery got', function () {
    ReportDelivery::create([
        'report_key' => 'daily-orders:2026-11-02',
        'report_date' => '2026-11-02',
        'status' => ReportDeliveryStatus::Pending,
        'row_count' => 1_500,
        'attempts' => 2,
        'exported_at' => '2026-11-03 11:00:05',
        'last_error' => 'UnableToWriteFile: Connection timed out',
    ]);

    $this->artisan('report:status')
        ->expectsTable(
            ['Day', 'Status', 'Orders', 'Exported', 'SFTP', 'Partner API', 'Email', 'Attempts', 'Last error'],
            [['2026-11-02', 'pending', '1,500', '11:00:05', '-', '-', '-', 2, 'UnableToWriteFile: Connection timed out']],
        )
        ->assertSuccessful();
});

it('says so when nothing has been delivered yet', function () {
    $this->artisan('report:status')->expectsOutput('No deliveries yet.')->assertSuccessful();
});

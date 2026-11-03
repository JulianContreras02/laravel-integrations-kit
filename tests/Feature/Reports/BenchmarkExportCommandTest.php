<?php

declare(strict_types=1);

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('measures each volume on a throwaway database and cleans up after itself', function () {
    Storage::fake('reports');
    Order::factory()->create();

    $this->artisan('report:benchmark', ['--rows' => '10,20'])->assertSuccessful();

    expect(DB::connection('sqlite')->table('orders')->count())->toBe(1)
        ->and(Storage::disk('reports')->allFiles())->toBeEmpty();
});

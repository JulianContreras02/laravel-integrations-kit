<?php

declare(strict_types=1);

use App\Models\Order;
use Carbon\CarbonImmutable;
use Database\Seeders\SyntheticOrders;

it('spreads synthetic orders over the business day, stored in UTC', function () {
    SyntheticOrders::insertForDay(CarbonImmutable::parse('2026-11-02'), 'America/Bogota', 48, chunkSize: 10);

    expect(Order::count())->toBe(48)
        ->and(Order::min('placed_at'))->toBe('2026-11-02 05:00:00')
        ->and(Order::max('placed_at'))->toBe('2026-11-03 04:30:00')
        ->and(Order::where('number', 'not like', SyntheticOrders::PREFIX.'%')->count())->toBe(0);
});

it('removes only synthetic orders', function () {
    Order::factory()->create();
    SyntheticOrders::insertForDay(CarbonImmutable::parse('2026-11-02'), 'UTC', 5);

    expect(SyntheticOrders::delete())->toBe(5)
        ->and(Order::count())->toBe(1);
});

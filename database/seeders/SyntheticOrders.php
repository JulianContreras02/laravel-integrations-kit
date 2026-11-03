<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fast bulk generator for worst-case volume tests and benchmarks.
 *
 * Factories build one model at a time and are too slow for hundreds of
 * thousands of rows, so this writes plain chunked inserts in one transaction.
 * Every order number starts with PREFIX, which makes synthetic rows easy to
 * count or delete with a single WHERE.
 */
final class SyntheticOrders
{
    public const string PREFIX = 'SYN-';

    private const array NAMES = ['Ana Torres', 'Luis Pérez', 'Marta Gómez', 'John Smith', 'Chen Wei', 'Amara Okafor', 'Sofia Rossi', 'Kenji Sato'];

    private const array CITIES = [['Bogotá', 'CO'], ['Medellín', 'CO'], ['Lima', 'PE'], ['Quito', 'EC'], ['Ciudad de México', 'MX'], ['Austin', 'US'], ['Madrid', 'ES']];

    /**
     * Inserts $count orders spread evenly over one business day.
     */
    public static function insertForDay(CarbonImmutable $day, string $timezone, int $count, int $chunkSize = 1_000): void
    {
        $startUtc = CarbonImmutable::parse($day->toDateString(), $timezone)->startOfDay()->utc();
        $statuses = OrderStatus::cases();
        $now = CarbonImmutable::now()->toDateTimeString();

        DB::transaction(function () use ($day, $count, $chunkSize, $startUtc, $statuses, $now): void {
            $rows = [];

            for ($i = 0; $i < $count; $i++) {
                [$city, $country] = self::CITIES[$i % count(self::CITIES)];

                $rows[] = [
                    'number' => self::PREFIX.$day->format('Ymd').'-'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                    'status' => $statuses[$i % count($statuses)]->value,
                    'customer_name' => self::NAMES[$i % count(self::NAMES)],
                    'customer_email' => "customer{$i}@shop.example",
                    'shipping_city' => $city,
                    'shipping_country' => $country,
                    'items_count' => random_int(1, 12),
                    'total_cents' => random_int(1_500, 450_000),
                    'currency' => 'USD',
                    'placed_at' => $startUtc->addSeconds(intdiv($i * 86_400, $count))->toDateTimeString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($rows) === $chunkSize) {
                    DB::table('orders')->insert($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                DB::table('orders')->insert($rows);
            }
        });
    }

    public static function delete(): int
    {
        return DB::table('orders')->where('number', 'like', self::PREFIX.'%')->delete();
    }
}

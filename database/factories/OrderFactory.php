<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'SO-'.fake()->unique()->numerify('########'),
            'status' => fake()->randomElement(OrderStatus::cases()),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->unique()->safeEmail(),
            'shipping_city' => fake()->city(),
            'shipping_country' => fake()->countryCode(),
            'items_count' => fake()->numberBetween(1, 12),
            'total_cents' => fake()->numberBetween(1_500, 450_000),
            'currency' => 'USD',
            'placed_at' => fake()->dateTimeBetween('-1 week'),
        ];
    }
}

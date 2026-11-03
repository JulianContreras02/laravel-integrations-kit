<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $number
 * @property OrderStatus $status
 * @property string $customer_name
 * @property string $customer_email
 * @property string $shipping_city
 * @property string $shipping_country
 * @property int $items_count
 * @property int $total_cents
 * @property string $currency
 * @property CarbonImmutable $placed_at
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'items_count' => 'integer',
            'total_cents' => 'integer',
            'placed_at' => 'immutable_datetime',
        ];
    }
}

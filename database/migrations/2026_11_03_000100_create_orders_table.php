<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('status', 16);
            $table->string('customer_name', 120);
            $table->string('customer_email', 190);
            $table->string('shipping_city', 100);
            $table->char('shipping_country', 2);
            $table->unsignedSmallInteger('items_count');
            $table->unsignedBigInteger('total_cents');
            $table->char('currency', 3);
            // Always stored in UTC; the report converts its business day to UTC.
            $table->timestamp('placed_at');
            $table->timestamps();

            // The export reads one day in id order (keyset pagination), so the
            // range and the cursor are served by the same index.
            $table->index(['placed_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

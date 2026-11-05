<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('order_id')
                  ->constrained('orders')
                  ->cascadeOnDelete();

            // A line is either a seat OR a GA quantity, never both
            $table->foreignUlid('seat_id')
                  ->nullable()
                  ->constrained('seats')
                  ->restrictOnDelete();

            $table->foreignUlid('ga_zone_id')
                  ->nullable()
                  ->constrained('ga_zones')
                  ->restrictOnDelete();

            // GA quantity (1 for seat items, N for GA)
            $table->unsignedSmallInteger('quantity')->default(1);

            // Which tier was sold
            $table->foreignUlid('price_tier_id')
                  ->constrained('price_tiers')
                  ->restrictOnDelete();

            // Price snapshot (frozen at purchase; never re-computed)
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('service_fee_cents')->default(0);
            $table->unsignedInteger('facility_fee_cents')->default(0);
            $table->unsignedInteger('processing_fee_cents')->default(0);
            $table->unsignedInteger('line_subtotal_cents');
            $table->unsignedInteger('line_total_cents');

            // Per-item promo allocation (for partial refunds)
            $table->unsignedInteger('line_discount_cents')->default(0);

            $table->timestamps();

            $table->index('order_id');
            $table->index('seat_id');
            $table->index('ga_zone_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
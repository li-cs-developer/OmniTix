<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_tier_pricing', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('event_id')
                  ->constrained('events')
                  ->cascadeOnDelete();

            $table->foreignUlid('price_tier_id')
                  ->constrained('price_tiers')
                  ->cascadeOnDelete();

            // Which sections can sell at this price for this event
            //   null = all sections
            //   ["ORCH", "VIPA"] = only these section codes
            $table->jsonb('applicable_sections')->default('[]');

            // The price (in cents — always integers)
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3)->default('USD');

            // Fees (all in cents, all separated for transparency at checkout)
            $table->unsignedInteger('service_fee_cents')->default(0);
            $table->unsignedInteger('facility_fee_cents')->default(0);
            $table->unsignedInteger('processing_fee_cents')->default(0);

            // Tax (v1: simple percentage; v2: jurisdiction matrix)
            $table->decimal('tax_rate', 5, 4)->default(0);   // 0.0825 = 8.25%
            $table->enum('tax_inclusive', ['inclusive', 'exclusive'])->default('exclusive');

            // Availability window (per tier — early bird, standard, late)
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();

            // Quantity cap (optional — null = unlimited within section)
            $table->unsignedInteger('quantity_cap')->nullable();

            // Display order
            $table->unsignedSmallInteger('display_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['event_id', 'price_tier_id'],
                          'event_tier_pricing_unique');
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tier_pricing');
    }
};
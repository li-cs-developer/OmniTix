<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('organizer_id')
                  ->constrained('organizers')
                  ->cascadeOnDelete();

            // Optional — if null, code applies across all events
            $table->foreignUlid('event_id')
                  ->nullable()
                  ->constrained('events')
                  ->cascadeOnDelete();

            // The code itself (uppercase, unique per organizer)
            $table->string('code');
            $table->text('description')->nullable();

            // Discount type
            $table->enum('discount_type', [
                'percent',        // 10% off
                'fixed',          // $5 off
                'bogo',           // buy 1 get 1 (applied to cheapest item)
            ]);

            // Value (percent = basis points, fixed = cents, bogo = unused)
            //   1000 = 10.00% discount
            //   500  = $5.00 discount
            $table->unsignedInteger('discount_value')->default(0);

            // Optional cap
            $table->unsignedInteger('max_discount_cents')->nullable();

            // Usage limits
            $table->unsignedInteger('max_redemptions')->nullable();        // total cap
            $table->unsignedInteger('max_redemptions_per_user')->default(1);
            $table->unsignedInteger('redemptions_count')->default(0);      // denormalized counter

            // Applicability
            $table->jsonb('applicable_sections')->default('[]');           // [] = all
            $table->jsonb('applicable_tier_ids')->default('[]');           // [] = all

            // Minimum order
            $table->unsignedInteger('min_order_cents')->default(0);

            // Validity window
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organizer_id', 'code']);
            $table->index(['code', 'is_active']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
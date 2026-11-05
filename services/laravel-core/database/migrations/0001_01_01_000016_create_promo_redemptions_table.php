<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_redemptions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('promo_code_id')
                  ->constrained('promo_codes')
                  ->cascadeOnDelete();

            $table->foreignUlid('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // Filled when the order is created (nullable to support pre-order reservation)
            $table->foreignUlid('order_id')
                  ->nullable()
                  ->constrained('orders')
                  ->nullOnDelete();

            // What was actually discounted (in cents)
            $table->unsignedInteger('discount_applied_cents')->default(0);

            // Anti-abuse
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamps();

            $table->index('promo_code_id');
            $table->index('user_id');
            $table->index(['promo_code_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_redemptions');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('order_id')
                  ->constrained('orders')
                  ->restrictOnDelete();

            // No FK constraint in v1 — payments table arrives in Phase 8.7.
            $table->string('payment_id', 26)->nullable();

            // Who issued the refund
            $table->foreignUlid('issued_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Amount in cents
            $table->unsignedInteger('amount_cents');

            $table->string('currency', 3)->default('USD');
            $table->string('reason')->nullable();

            // Provider reference (from Kotlin ledger)
            $table->string('provider_ref')->nullable();

            // Which tickets this refund voids (empty = all)
            $table->jsonb('affected_ticket_ids')->default('[]');

            $table->enum('status', [
                'pending',    // refund requested, awaiting provider
                'succeeded',  // provider confirmed
                'failed',     // provider rejected
            ])->default('pending');

            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
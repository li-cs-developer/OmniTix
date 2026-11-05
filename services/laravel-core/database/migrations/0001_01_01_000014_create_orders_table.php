<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Human-readable order reference (e.g. "OTX-7K3M9Q")
            $table->string('reference', 20)->unique();

            // Who
            $table->foreignUlid('user_id')
                  ->nullable()                        // null = guest checkout
                  ->constrained('users')
                  ->nullOnDelete();

            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();

            // What event / occurrence
            $table->foreignUlid('event_id')
                  ->constrained('events')
                  ->restrictOnDelete();

            $table->foreignUlid('event_occurrence_id')
                  ->constrained('event_occurrences')
                  ->restrictOnDelete();

            // Applied promo (if any)
            $table->foreignUlid('promo_code_id')
                  ->nullable()
                  ->constrained('promo_codes')
                  ->nullOnDelete();

            // Money (all cents, all integer)
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('service_fee_cents')->default(0);
            $table->unsignedInteger('facility_fee_cents')->default(0);
            $table->unsignedInteger('processing_fee_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents');

            $table->string('currency', 3)->default('USD');

            // State machine
            $table->enum('status', [
                'draft',              // cart building, holds active
                'pending_payment',    // holds active, payment in flight
                'paid',               // payment confirmed
                'fulfilled',          // tickets issued, email sent
                'expired',            // holds expired before payment
                'cancelled',          // user cancelled
                'refunded',           // fully refunded
                'partially_refunded', // some items refunded
                'failed',             // payment failed
            ])->default('draft');

            // Hold expiration — this is the TTL we mirror in Redis
            $table->timestamp('hold_expires_at')->nullable();

            // Fulfillment
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            // Idempotency key from the client (prevents double-submit)
            $table->string('idempotency_key', 64)->nullable();

            // Anti-fraud
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->jsonb('metadata')->default('{}');

            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('hold_expires_at');
            $table->index(['event_occurrence_id', 'status']);
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('order_id')
                  ->constrained('orders')
                  ->restrictOnDelete();

            $table->foreignUlid('order_item_id')
                  ->constrained('order_items')
                  ->restrictOnDelete();

            $table->foreignUlid('event_id')
                  ->constrained('events')
                  ->restrictOnDelete();

            $table->foreignUlid('event_occurrence_id')
                  ->constrained('event_occurrences')
                  ->restrictOnDelete();

            // Seat or GA (mirrors order_items)
            $table->foreignUlid('seat_id')
                  ->nullable()
                  ->constrained('seats')
                  ->nullOnDelete();

            $table->foreignUlid('ga_zone_id')
                  ->nullable()
                  ->constrained('ga_zones')
                  ->nullOnDelete();

            // Current owner (buyer by default; transferable in v2)
            $table->foreignUlid('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Human-readable ticket identifier (unique, printed on PDF)
            $table->string('ticket_number', 32)->unique();

            // Tier at time of purchase
            $table->foreignUlid('price_tier_id')
                  ->constrained('price_tiers')
                  ->restrictOnDelete();

            // ─── Cryptographic QR payload ────────────
            // Populated by Rust Crypto service after payment
            $table->text('qr_payload')->nullable();       // base64 canonical JSON
            $table->string('qr_signature', 256)->nullable();
            $table->string('signer_key_id', 32)->nullable(); // e.g. "v1"
            $table->timestamp('qr_issued_at')->nullable();

            // ─── Lifecycle ───────────────────────────
            $table->enum('status', [
                'pending',     // created, awaiting signature
                'issued',      // QR signed, ready to scan
                'transferred', // v2
                'scanned',     // checked in
                'voided',      // refunded or cancelled
            ])->default('pending');

            // Scan tracking (denormalized from ticket_scans for fast queries)
            $table->timestamp('scanned_at')->nullable();
            $table->foreignUlid('scanned_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->unsignedSmallInteger('scan_count')->default(0);

            // Refund tracking
            $table->boolean('is_refunded')->default(false);
            $table->timestamp('refunded_at')->nullable();

            // Attendee (v1: optional — buyer is assumed attendee)
            $table->string('attendee_name')->nullable();
            $table->string('attendee_email')->nullable();

            $table->jsonb('metadata')->default('{}');

            $table->timestamps();

            $table->index('order_id');
            $table->index('event_occurrence_id');
            $table->index('status');
            $table->index('ticket_number');
            $table->index(['event_occurrence_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
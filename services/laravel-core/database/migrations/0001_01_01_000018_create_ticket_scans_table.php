<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_scans', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('ticket_id')
                  ->constrained('tickets')
                  ->cascadeOnDelete();

            // Who scanned (gate operator)
            $table->foreignUlid('scanned_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Where (which gate/device)
            $table->string('gate_identifier')->nullable();   // "Gate A", "Scanner-07"

            // Result
            $table->enum('result', [
                'accepted',       // valid first scan
                'already_used',   // previously scanned
                'invalid_sig',    // signature failed
                'voided',         // refunded ticket
                'wrong_event',    // ticket for a different event
                'expired',        // post-event
                'unknown',        // QR unreadable
            ]);

            // Diagnostic
            $table->string('scanner_ip', 45)->nullable();
            $table->string('scanner_user_agent', 500)->nullable();
            $table->jsonb('context')->default('{}');

            $table->timestamp('scanned_at');

            $table->timestamps();

            $table->index('ticket_id');
            $table->index(['ticket_id', 'result']);
            $table->index('scanned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_scans');
    }
};
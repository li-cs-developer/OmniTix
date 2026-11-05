<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_occurrences', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('event_id')
                  ->constrained('events')
                  ->cascadeOnDelete();

            // Timing (all UTC; venue local TZ rendered on frontend)
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('doors_open_at')->nullable();

            // Per-occurrence overrides
            $table->string('label')->nullable();           // "Opening Night", "Saturday Matinee"

            // Availability snapshot (denormalized counters, updated by batch jobs)
            $table->unsignedInteger('capacity_total')->default(0);
            $table->unsignedInteger('capacity_sold')->default(0);
            $table->unsignedInteger('capacity_held')->default(0);

            // Sales window override (falls back to event if null)
            $table->timestamp('sales_start_at')->nullable();
            $table->timestamp('sales_end_at')->nullable();

            $table->enum('status', [
                'scheduled', 'on_sale', 'sold_out',
                'postponed', 'cancelled', 'completed',
            ])->default('scheduled');

            $table->jsonb('settings')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->index('event_id');
            $table->index('starts_at');
            $table->index('status');
            $table->unique(['event_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_occurrences');
    }
};
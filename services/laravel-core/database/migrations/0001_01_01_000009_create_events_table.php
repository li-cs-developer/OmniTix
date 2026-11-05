<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('organizer_id')
                  ->constrained('organizers')
                  ->cascadeOnDelete();

            $table->foreignUlid('venue_id')
                  ->constrained('venues')
                  ->restrictOnDelete();

            $table->foreignUlid('seat_map_id')
                  ->constrained('seat_maps')
                  ->restrictOnDelete();

            // Identity
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();

            // Media
            $table->string('hero_image_url')->nullable();
            $table->jsonb('gallery')->default('[]');       // array of image URLs

            // Categorization
            $table->enum('category', [
                'music', 'sports', 'theater', 'comedy', 'conference',
                'festival', 'family', 'other',
            ])->default('music');

            $table->jsonb('tags')->default('[]');          // ["rock", "live", "18+"]

            // Performer / presenter info
            $table->string('performer_name')->nullable();
            $table->text('performer_bio')->nullable();

            // Age restriction
            $table->unsignedTinyInteger('age_restriction')->default(0);  // 0, 18, 21

            // Sales window (across all occurrences)
            $table->timestamp('sales_start_at')->nullable();
            $table->timestamp('sales_end_at')->nullable();

            // Lifecycle
            $table->enum('status', [
                'draft', 'published', 'on_sale', 'sold_out',
                'postponed', 'cancelled', 'completed',
            ])->default('draft');

            // Purchase limits (per buyer, per event)
            $table->unsignedSmallInteger('max_tickets_per_user')->default(8);
            $table->unsignedSmallInteger('max_tickets_per_card')->default(8);

            // Refund policy
            $table->unsignedSmallInteger('refund_cutoff_hours')->default(48);
            $table->boolean('allow_refunds_after_start')->default(false);

            // Flexible metadata
            $table->jsonb('settings')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->index('organizer_id');
            $table->index('venue_id');
            $table->index('status');
            $table->index('category');
            $table->index('sales_start_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
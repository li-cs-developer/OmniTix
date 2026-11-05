<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_maps', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('venue_id')
                  ->constrained('venues')
                  ->cascadeOnDelete();

            $table->unsignedSmallInteger('version')->default(1);
            $table->string('name')->nullable();            // "Original 2024 layout"
            $table->text('notes')->nullable();

            // Full map dimensions in "seat units" (1 unit ≈ 1 seat)
            // Next.js uses these to scale the SVG viewport.
            $table->unsignedInteger('width')->default(1000);
            $table->unsignedInteger('height')->default(1000);

            // Overall metadata: stage position, orientation, legend colors
            //   stage: { x, y, w, h, label }
            //   orientation: "stage_up" | "stage_down" | "stage_left" | "stage_right"
            $table->jsonb('meta')->default('{}');

            $table->boolean('is_published')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['venue_id', 'version']);
            $table->index('is_published');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_maps');
    }
};
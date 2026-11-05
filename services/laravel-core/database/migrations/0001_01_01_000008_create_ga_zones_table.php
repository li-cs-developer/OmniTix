<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga_zones', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('seat_map_id')
                  ->constrained('seat_maps')
                  ->cascadeOnDelete();

            $table->foreignUlid('venue_section_id')
                  ->constrained('venue_sections')
                  ->cascadeOnDelete();

            $table->string('name');                        // "Standing Pit", "Lawn"
            $table->string('code')->nullable();            // "PIT", "LAWN"

            // Capacity is the total pool size. Actual availability lives in Redis.
            $table->unsignedInteger('capacity');

            // Visual (rendered as a clickable polygon/rect on the map)
            //   path: SVG path data
            //   label_x, label_y: where the "Standing Pit" text appears
            $table->jsonb('visual')->default('{}');

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['seat_map_id', 'code']);
            $table->index('seat_map_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga_zones');
    }
};
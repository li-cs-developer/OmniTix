<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seats', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('seat_map_id')
                  ->constrained('seat_maps')
                  ->cascadeOnDelete();

            $table->foreignUlid('venue_section_id')
                  ->constrained('venue_sections')
                  ->cascadeOnDelete();

            // Human identifiers
            $table->string('section');                     // denormalized for fast queries
            $table->string('row_label');                   // "A", "12", "AA"
            $table->string('seat_number');                 // "1", "12B"
            $table->string('display_label');               // "Orchestra A12"

            // Position on the map (SVG coordinates)
            $table->decimal('x', 10, 2);
            $table->decimal('y', 10, 2);
            $table->decimal('rotation', 5, 2)->default(0); // rotation in degrees

            // Attributes for filtering / rendering
            //   wheelchair: bool
            //   companion: bool  (companion seat for wheelchair user)
            //   obstructed: bool
            //   limited_view: bool
            //   premium: bool
            $table->jsonb('attributes')->default('{}');

            // Flexible extras (some venues want aisle, exit row, etc.)
            $table->jsonb('meta')->default('{}');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Unique within a seat map version
            $table->unique(['seat_map_id', 'section', 'row_label', 'seat_number'],
                          'seats_unique_position');

            // Query patterns:
            //   - "all seats in section X of map Y" → first index
            //   - "all wheelchair seats" → GIN on attributes
            //   - "seats in a bounding box" → composite on x,y
            $table->index(['seat_map_id', 'venue_section_id']);
            $table->index(['seat_map_id', 'section', 'row_label']);
        });

        // GIN index for JSONB attribute queries (e.g. WHERE attributes @> '{"wheelchair":true}')
        DB::statement('CREATE INDEX seats_attributes_gin ON seats USING GIN (attributes)');
    }

    public function down(): void
    {
        Schema::dropIfExists('seats');
    }
};
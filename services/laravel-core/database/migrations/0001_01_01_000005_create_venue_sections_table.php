<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('venue_id')
                  ->constrained('venues')
                  ->cascadeOnDelete();

            // Identity
            $table->string('name');                        // "Orchestra", "VIP Box A"
            $table->string('code')->nullable();            // "ORCH", "VIPA"
            $table->enum('kind', [
                'seated',                                   // numbered rows
                'ga',                                       // general admission pool
                'box',                                      // luxury box
                'table',                                    // nightclub tables
                'accessible',                               // wheelchair spaces
            ])->default('seated');

            $table->text('description')->nullable();

            // Visuals (rendered by Next.js seat map)
            //    color: hex string
            //    path: SVG path data for irregular sections
            //    bounds: { x, y, w, h } for simple rectangles
            $table->jsonb('visual')->default('{}');

            // Ordering for display
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('venue_id');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_sections');
    }
};
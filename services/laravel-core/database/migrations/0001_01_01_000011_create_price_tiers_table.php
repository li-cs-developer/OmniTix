<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_tiers', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('organizer_id')
                  ->constrained('organizers')
                  ->cascadeOnDelete();

            // Identity
            $table->string('name');                        // "Adult", "Child", "VIP"
            $table->string('code')->nullable();            // "ADULT", "CHILD", "VIP"
            $table->text('description')->nullable();

            // Who can buy (for age-restricted tiers)
            $table->unsignedTinyInteger('min_age')->default(0);
            $table->unsignedTinyInteger('max_age')->default(0); // 0 = no max

            // Visual (color-coded on seat map + pricing table)
            $table->string('color', 7)->default('#6c5ce7');

            // Display order in the pricing table
            $table->unsignedSmallInteger('display_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organizer_id', 'code']);
            $table->index('organizer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_tiers');
    }
};
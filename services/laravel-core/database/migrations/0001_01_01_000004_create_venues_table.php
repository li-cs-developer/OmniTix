<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('organizer_id')
                  ->nullable()
                  ->constrained('organizers')
                  ->nullOnDelete();

            // Identity
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Address
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country', 2)->default('US');

            // Coordinates (for maps + "nearest event")
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Contact
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // Operational
            $table->string('timezone')->default('America/New_York');
            $table->unsignedInteger('total_capacity')->default(0);
            $table->jsonb('settings')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->index('organizer_id');
            $table->index(['city', 'state']);
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
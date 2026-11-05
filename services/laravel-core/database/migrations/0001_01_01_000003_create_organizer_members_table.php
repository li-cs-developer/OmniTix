<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_members', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('organizer_id')
                  ->constrained('organizers')
                  ->cascadeOnDelete();

            $table->foreignUlid('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->enum('role', [
                'owner',
                'admin',
                'box_office',
                'scanner',
                'finance',
            ]);

            // Who invited them (nullable — the founder has no inviter)
            $table->foreignUlid('invited_by_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // A user can only hold one role per organizer
            $table->unique(['organizer_id', 'user_id']);
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_members');
    }
};
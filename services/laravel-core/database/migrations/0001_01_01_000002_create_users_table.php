<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // A user can belong to an organizer (organizer staff)
            // or be a pure buyer (organizer_id = null).
            $table->foreignUlid('organizer_id')
                  ->nullable()
                  ->constrained('organizers')
                  ->nullOnDelete();

            // Auth
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable(); // nullable: guest buyers have no password yet
            $table->rememberToken();

            // Identity
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('timezone')->nullable();

            // Global role (organizer-scoped roles live in organizer_members)
            $table->enum('role', [
                'buyer',
                'organizer_owner',
                'organizer_admin',
                'box_office',
                'scanner',
                'finance',
                'super_admin',
            ])->default('buyer');

            // Anti-fraud signals (v1: basic)
            $table->boolean('is_banned')->default(false);
            $table->text('ban_reason')->nullable();
            $table->timestamp('last_login_at')->nullable();

            // Flexible metadata
            $table->jsonb('settings')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->index('role');
            $table->index('organizer_id');
        });

        // Laravel's password reset tokens (kept — small, useful for v1)
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
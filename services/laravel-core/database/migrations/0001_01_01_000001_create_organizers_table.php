<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizers', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Identity
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->string('logo_url')->nullable();

            // Business
            $table->string('legal_name')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('country', 2)->default('US');
            $table->string('currency', 3)->default('USD');
            $table->string('timezone')->default('America/New_York');

            // KYC / verification (v1: stored only, no real KYC vendor)
            $table->enum('kyc_status', ['pending', 'approved', 'rejected'])
                  ->default('pending');
            $table->timestamp('kyc_verified_at')->nullable();
            $table->text('kyc_notes')->nullable();

            // Payouts (real Stripe in v2; mocked in v1)
            $table->enum('payout_schedule', ['daily', 'weekly', 'post_event'])
                  ->default('weekly');
            $table->unsignedSmallInteger('holdback_days')->default(7);
            $table->string('stripe_account_id')->nullable();

            // Flexible metadata — theme colors, feature flags, etc.
            $table->jsonb('settings')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->index('kyc_status');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizers');
    }
};
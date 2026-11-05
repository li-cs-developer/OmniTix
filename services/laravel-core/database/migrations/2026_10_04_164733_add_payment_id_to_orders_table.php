<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_id', 26)->nullable()->after('idempotency_key');
            $table->string('provider_ref')->nullable()->after('payment_id');
            $table->timestamp('payment_captured_at')->nullable()->after('provider_ref');

            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_id']);
            $table->dropColumn(['payment_id', 'provider_ref', 'payment_captured_at']);
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sanctum's default migration creates tokenable_id as BIGINT.
        // Our User and other models use ULIDs (26-char strings).
        // Widen it to VARCHAR(26).
        DB::statement('
            ALTER TABLE app.personal_access_tokens
            ALTER COLUMN tokenable_id TYPE VARCHAR(26)
            USING tokenable_id::VARCHAR(26)
        ');
    }

    public function down(): void
    {
        DB::statement('
            ALTER TABLE app.personal_access_tokens
            ALTER COLUMN tokenable_id TYPE BIGINT
            USING tokenable_id::BIGINT
        ');
    }
};

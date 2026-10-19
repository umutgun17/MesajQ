<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE INDEX products_metadata_trendyol_content_id
            ON products ((metadata->>'trendyol_content_id'))
            WHERE metadata->>'trendyol_content_id' IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_metadata_trendyol_content_id');
    }
};

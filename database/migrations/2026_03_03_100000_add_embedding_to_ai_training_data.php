<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_training_data ADD COLUMN embedding vector(1536)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ai_training_data DROP COLUMN IF EXISTS embedding');
    }
};

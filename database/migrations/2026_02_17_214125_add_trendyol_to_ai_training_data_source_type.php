<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE ai_training_data DROP CONSTRAINT IF EXISTS ai_training_data_source_type_check");
        DB::statement("ALTER TABLE ai_training_data ADD CONSTRAINT ai_training_data_source_type_check CHECK (source_type::text = ANY (ARRAY['conversation', 'manual', 'trendyol']::text[]))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE ai_training_data DROP CONSTRAINT IF EXISTS ai_training_data_source_type_check");
        DB::statement("ALTER TABLE ai_training_data ADD CONSTRAINT ai_training_data_source_type_check CHECK (source_type::text = ANY (ARRAY['conversation', 'manual']::text[]))");
    }
};

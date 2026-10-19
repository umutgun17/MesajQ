<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_response_logs', function (Blueprint $table) {
            $table->decimal('confidence_score', 3, 2)->nullable()->after('model_used');
            $table->jsonb('detected_intent')->nullable()->after('confidence_score');
            $table->jsonb('extracted_entities')->nullable()->after('detected_intent');
        });
    }

    public function down(): void
    {
        Schema::table('ai_response_logs', function (Blueprint $table) {
            $table->dropColumn(['confidence_score', 'detected_intent', 'extracted_entities']);
        });
    }
};

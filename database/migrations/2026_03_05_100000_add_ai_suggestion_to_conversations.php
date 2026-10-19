<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->text('ai_suggestion')->nullable()->after('metadata');
            $table->decimal('ai_suggestion_confidence', 3, 2)->nullable()->after('ai_suggestion');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['ai_suggestion', 'ai_suggestion_confidence']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_review_summaries', function (Blueprint $table) {
            $table->text('ai_summary')->nullable()->after('rate_one');
        });
    }

    public function down(): void
    {
        Schema::table('product_review_summaries', function (Blueprint $table) {
            $table->dropColumn('ai_summary');
        });
    }
};

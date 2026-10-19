<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('performance_label', 2)->nullable()->after('variant_stock_labels');
            $table->decimal('performance_score', 5, 2)->nullable()->after('performance_label');
            $table->jsonb('performance_score_breakdown')->nullable()->after('performance_score');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['performance_label', 'performance_score', 'performance_score_breakdown']);
        });
    }
};

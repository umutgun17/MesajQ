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
        Schema::table('product_review_summaries', function (Blueprint $table) {
            // Yorumlarin en son senkronize edildigi andaki Trendyol ortalama puani
            // (fiyat taramasi/maximedupre kaynakli, 3 ondalik). Yorum sync'i, guncel
            // trendyol_rating bununla ayniysa pahali yorum aktorunu atlar.
            $table->decimal('reviews_synced_rating', 4, 3)->nullable()->after('avg_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_review_summaries', function (Blueprint $table) {
            $table->dropColumn('reviews_synced_rating');
        });
    }
};

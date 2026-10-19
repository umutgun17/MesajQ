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
            // Trendyol kampanya/kupon/kargo metinleri (ör. "Sepette %10", "Kargo Bedava").
            // Alici-fiyati (trendyol_price) genelde sepet-kupon ONCESI gorunen satis fiyatidir;
            // net fiyat icin bu promosyonlar dikkate alinmalidir.
            $table->jsonb('trendyol_promotions')->nullable()->after('trendyol_original_price');
            $table->decimal('trendyol_discount_percent', 5, 2)->nullable()->after('trendyol_promotions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['trendyol_promotions', 'trendyol_discount_percent']);
        });
    }
};

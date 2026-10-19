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
            // Apify (maximedupre) urun taramasindan gelen Trendyol alici-fiyati.
            $table->decimal('trendyol_price', 12, 2)->nullable()->after('discounted_price');
            $table->decimal('trendyol_original_price', 12, 2)->nullable()->after('trendyol_price');
            $table->boolean('trendyol_in_stock')->nullable()->after('trendyol_original_price');
            // Ortalama puan — yorumlarin degisip degismedigini ucuz tespit icin de kullanilir.
            $table->decimal('trendyol_rating', 4, 3)->nullable()->after('trendyol_in_stock');
            $table->string('trendyol_product_url', 500)->nullable()->after('trendyol_rating');
            $table->timestamp('trendyol_price_synced_at')->nullable()->after('trendyol_product_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'trendyol_price',
                'trendyol_original_price',
                'trendyol_in_stock',
                'trendyol_rating',
                'trendyol_product_url',
                'trendyol_price_synced_at',
            ]);
        });
    }
};

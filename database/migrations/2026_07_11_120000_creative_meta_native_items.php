<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kreatif Stüdyo v5.2 — Meta setleri yerel ürün kataloğuyla EŞLEŞTİRİLMEZ.
 * Set ürünleri (retailer_id + görsel/fiyat/ad) doğrudan Meta'dan alınıp
 * product_set_items.payload'a yazılır; render ve supplementary feed bu
 * veriden çalışır (g:id = retailer_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_set_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
            $table->string('retailer_id', 191)->nullable();
            $table->jsonb('payload')->nullable(); // {name,brand,images,price,sale_price,currency,url,availability}
            $table->unique(['product_set_id', 'retailer_id']);
        });

        Schema::table('enhanced_images', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
            $table->string('retailer_id', 191)->nullable();
            $table->unique(['enhanced_catalog_id', 'retailer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('enhanced_images', function (Blueprint $table) {
            $table->dropUnique(['enhanced_catalog_id', 'retailer_id']);
            $table->dropColumn('retailer_id');
        });
        Schema::table('product_set_items', function (Blueprint $table) {
            $table->dropUnique(['product_set_id', 'retailer_id']);
            $table->dropColumn(['retailer_id', 'payload']);
        });
    }
};

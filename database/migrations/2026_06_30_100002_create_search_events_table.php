<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akıllı Arama — sorgu sonrası ürün etkileşimleri (gösterim/tıklama/sepete ekleme/dönüşüm).
 * Ranking ve agentic döngü için geri besleme sinyali; CTR ve arama→satış ölçümü.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('search_query_id')->nullable()->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('platform_product_id', 64)->nullable();

            $table->string('type', 20);                             // impression|click|add_to_cart|conversion
            $table->unsignedSmallInteger('position')->nullable();   // sonuç içindeki sıra
            $table->string('session_id', 64)->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_test')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Birlikte-alım özet tablosu (Sepet Önerisi motoru).
 * Gecelik rec:build-copurchases komutu doldurur: ödenmiş çoklu-ürün siparişlerden
 * ürün çiftleri, ürün başına en güçlü N partner tutulur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_copurchases', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('rec_product_id');
            $table->unsignedInteger('together_count')->default(0);
            $table->timestamp('last_together_at')->nullable();
            $table->timestamp('built_at')->nullable();

            $table->primary(['tenant_id', 'product_id', 'rec_product_id']);
            $table->index(['tenant_id', 'product_id', 'together_count'], 'rc_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_copurchases');
    }
};

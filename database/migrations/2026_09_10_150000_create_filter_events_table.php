<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akıllı Filtre (CRO) — storefront filtre widget'ının kullanım olayları.
 * type: view (kategori sayfası açıldı) | apply (facet değeri seçildi) | clear | click (ürün) | atc | conversion.
 * Panelde "hangi filtre ne kadar kullanılıyor, filtre kullanan ziyaretçi tıklıyor mu" analizi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filter_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('session_id', 64)->nullable();
            $table->string('type', 16);
            $table->string('category_path', 200)->nullable();
            $table->string('facet', 48)->nullable();
            $table->string('value', 120)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('value_num', 12, 2)->nullable();
            $table->string('platform_order_id', 64)->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'facet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_events');
    }
};

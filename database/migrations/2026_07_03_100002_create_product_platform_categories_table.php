<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori Denetçisi — ürün↔kategori atamalarının yerel aynası (SelectUrunKategori).
 * Hem sınıflandırma (ürün hangi kategorilerde) hem Akıllı Kategori Sıralaması
 * (kategori havuzu + mevcut Sira diff'i) bu tablodan beslenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_platform_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index(); // products.id (eşleşmezse null)
            $table->unsignedInteger('platform_product_id');    // Ticimax UrunKartiID
            $table->unsignedInteger('platform_category_id');   // Ticimax KategoriID
            $table->integer('sira')->default(9999);            // kategori-içi sıra (9999=sırasız)
            $table->boolean('is_primary')->default(false);     // ürünün AnaKategori'si mi
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform_product_id', 'platform_category_id'], 'ppc_tenant_prod_cat_unique');
            $table->index(['tenant_id', 'platform_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_platform_categories');
    }
};

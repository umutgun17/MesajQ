<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trendyol Seller API (/integration/product/sellers/{id}/products) katalogunun
     * barkod (varyant) bazli kopyasi. Site (Ticimax) katalogu ile kiyaslama icin
     * kullanilir: sitede olmayan urunler, fiyat/gorsel/stok/baslik farklari.
     */
    public function up(): void
    {
        Schema::create('trendyol_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('channel_id')->nullable();
            $table->string('barcode', 64);
            $table->string('trendyol_id', 64)->nullable();            // content.id (urun kaydi)
            $table->unsignedBigInteger('product_content_id')->nullable(); // vitrin contentId (URL'deki -p-XXXX)
            $table->string('product_main_id', 128)->nullable()->index();  // model kodu
            $table->string('stock_code', 128)->nullable()->index();
            $table->string('title', 500)->nullable();
            $table->string('brand', 128)->nullable();
            $table->string('category_name', 255)->nullable();
            $table->unsignedBigInteger('pim_category_id')->nullable();
            $table->string('color', 128)->nullable();
            $table->string('size', 64)->nullable();
            $table->string('gender', 32)->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('list_price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->jsonb('images')->nullable();
            $table->unsignedSmallInteger('image_count')->default(0);
            $table->jsonb('attributes')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('description_length')->default(0);
            $table->boolean('approved')->nullable();
            $table->boolean('on_sale')->nullable();
            $table->boolean('archived')->nullable();
            $table->boolean('locked')->nullable();
            $table->boolean('blacklisted')->nullable();
            $table->boolean('rejected')->nullable();
            $table->boolean('has_active_campaign')->nullable();
            $table->string('product_url', 500)->nullable();
            $table->timestamp('platform_created_at')->nullable();
            $table->timestamp('platform_updated_at')->nullable();

            // Site eslesmesi
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('match_type', 32)->nullable(); // variant_barcode | product_barcode | stock_code | main_id | none

            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_missing')->default(false); // son taramada Trendyol listesinde yoktu
            $table->timestamps();

            $table->unique(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'product_content_id']);
            $table->index(['tenant_id', 'is_missing']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trendyol_products');
    }
};

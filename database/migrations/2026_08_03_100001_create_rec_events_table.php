<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sepet Önerisi olay tablosu (search_events deseni).
 * type: impression | click | atc | conversion
 * source_product_id = müşterinin sepete eklediği (öneriyi tetikleyen) ürün,
 * product_id = önerilen ürün. Atıf cron'u için ip_address burada tutulur
 * (arama tarafında IP search_queries'teydi; burada ayrı sorgu tablosu yok).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('type', 20);
            $table->unsignedBigInteger('source_product_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('platform_product_id', 64)->nullable();
            $table->string('variant_size', 32)->nullable();
            $table->smallInteger('position')->nullable();
            $table->string('session_id', 64)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->boolean('is_test')->default(false);
            $table->decimal('value', 12, 2)->nullable();
            $table->string('platform_order_id', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'created_at'], 're_tenant_type_idx');
            $table->index('product_id', 're_product_idx');
            $table->index('session_id', 're_session_idx');
            $table->index(['tenant_id', 'platform_order_id'], 're_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_events');
    }
};

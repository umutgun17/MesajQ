<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Bugüne Özel" Hero Ürün kulakçığı (CRO) olayları:
 * view (kulakçık gösterildi) | open (çekmece açıldı; source tab|auto|exit|scroll|api) | close |
 * click (ürün linki) | atc (çekmeceden sepete ekleme) | coupon (kupon kopyalandı) |
 * conversion (hero:attribute-conversions — IP+ürün+24s, sipariş başına 1) | error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hero_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('session_id', 64)->nullable();
            $table->string('type', 16);
            $table->string('source', 16)->nullable();        // open: tab|auto|exit|scroll|api
            $table->string('set_id', 24)->nullable();        // gösterilen set
            $table->unsignedBigInteger('product_id')->nullable(); // products.id (click/atc/conversion)
            $table->string('value', 160)->nullable();        // atc: variant id/beden; error: mesaj
            $table->decimal('value_num', 12, 2)->nullable(); // conversion: sipariş tutarı
            $table->string('platform_order_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();    // atıf için (IP+ürün+24s)
            $table->string('page_path', 200)->nullable();
            $table->string('page_type', 16)->nullable();
            $table->string('device', 8)->nullable();         // m|d
            $table->boolean('is_test')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'product_id']);
            $table->index(['tenant_id', 'platform_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sana Özel" öneri kulakçığı (CRO) olayları:
 * view (kulakçık gösterildi; oturumda 1) | nudge (küçük ipucu balonu) | open (panel açıldı; source tab|nudge) | close |
 * impression (panelde gösterilen ürünler; value = id listesi) | click (ürün sayfasına gitti) | atc (panelden sepete ekleme) |
 * conversion (foryou:attribute-conversions — ikas'ta sipariş id = sepet id → birebir; yedek: müşteri + 24s) | error.
 * mode = önerinin kaynağı: bestseller | category | viewed | cart | history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foryou_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('session_id', 64)->nullable();
            $table->string('type', 16);
            $table->string('mode', 16)->nullable();
            $table->string('source', 16)->nullable();        // open: tab|nudge
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedSmallInteger('position')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable(); // customers.id (üye tanındıysa)
            $table->string('cart_id', 64)->nullable();         // mağaza sepet kimliği (ikas: sipariş id ile aynı)
            $table->string('value', 160)->nullable();
            $table->decimal('value_num', 12, 2)->nullable();   // conversion: önerilen ürünün kalem tutarı
            $table->string('platform_order_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('page_path', 200)->nullable();
            $table->string('page_type', 16)->nullable();
            $table->string('device', 8)->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'product_id']);
            $table->index(['tenant_id', 'cart_id']);
            $table->index(['tenant_id', 'platform_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foryou_events');
    }
};

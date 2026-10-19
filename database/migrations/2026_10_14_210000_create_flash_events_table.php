<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Flaş İndirim" ana sayfa vitrini (CRO) olayları:
 * view (vitrin ≥%50 görünür oldu, oturum başına 1) | click (ürün) | atc (kart içinden sepete) | coupon (kupon kopyalandı) |
 * tab (segment sekmesi) | cta (alt bağlantı) | conversion (flash:attribute-conversions — IP+ürün+24s) | error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('session_id', 64)->nullable();
            $table->string('type', 16);
            $table->string('layout', 16)->nullable();
            $table->string('set_id', 24)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('value', 160)->nullable();
            $table->decimal('value_num', 12, 2)->nullable();
            $table->string('platform_order_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('page_path', 200)->nullable();
            $table->string('device', 8)->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'product_id']);
            $table->index(['tenant_id', 'platform_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_events');
    }
};

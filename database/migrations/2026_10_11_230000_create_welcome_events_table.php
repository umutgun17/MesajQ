<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Hoş geldin" ilk alışveriş hediyesi (CRO) olayları:
 * view (kart gösterildi; source = tetik: delay|pages|exit) | reveal (kod açıldı) | copy (kod kopyalandı) | dismiss (kapatıldı) |
 * cta (alışverişe başla) | remind (hatırlatıcı pilden kopyalama) |
 * conversion (welcome:attribute-conversions — kupon kodlu sipariş [kesin] ya da kartı gören sepetin siparişi) | error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('welcome_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('session_id', 64)->nullable();
            $table->string('type', 16);
            $table->string('source', 16)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('cart_id', 64)->nullable();
            $table->string('value', 160)->nullable();          // conversion: kupon kodu / error: mesaj
            $table->decimal('value_num', 12, 2)->nullable();   // conversion: sipariş tutarı
            $table->decimal('discount', 12, 2)->nullable();    // conversion: verilen indirim
            $table->string('platform_order_id', 64)->nullable();
            $table->string('page_path', 200)->nullable();
            $table->string('page_type', 16)->nullable();
            $table->string('device', 8)->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'cart_id']);
            $table->index(['tenant_id', 'platform_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('welcome_events');
    }
};

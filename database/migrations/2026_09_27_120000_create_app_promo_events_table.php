<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Uygulamaya Özel" kulakçığı (CRO) olayları:
 * view (kulakçık gösterildi) | open (panel açıldı; source tab|auto|scroll|exit|api) | close |
 * click (mağaza butonu; source ios|android|landing) | copy (kod kopyalandı) |
 * redirect (/go yönlendirmesi; source qr|tab|panel…, platform ios|android|desktop|other) | error.
 * Kod kullanımı ve uygulama siparişleri orders tablosundan (coupon_code + order_source Native*) okunur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_promo_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('session_id', 64)->nullable();
            $table->string('type', 16);
            $table->string('source', 24)->nullable();
            $table->string('platform', 16)->nullable();      // ios|android|desktop|other|inapp_ios|inapp_android
            $table->string('value', 160)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 200)->nullable();   // yalnız redirect
            $table->string('page_path', 200)->nullable();
            $table->string('page_type', 16)->nullable();
            $table->string('device', 8)->nullable();         // m|d
            $table->boolean('is_test')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'created_at']);
            $table->index(['tenant_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_promo_events');
    }
};

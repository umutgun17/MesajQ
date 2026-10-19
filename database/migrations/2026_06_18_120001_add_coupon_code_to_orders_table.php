<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Siparişte kullanılan hediye çeki / kupon kodu (Ticimax WebSiparis.HediyeCeki).
            // Çark modülü kupon kodunu buradan eşleştirip kullanım/sipariş takibi yapar.
            $table->string('coupon_code')->nullable()->after('gift_card_amount');
            $table->index(['tenant_id', 'coupon_code']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'coupon_code']);
            $table->dropColumn('coupon_code');
        });
    }
};

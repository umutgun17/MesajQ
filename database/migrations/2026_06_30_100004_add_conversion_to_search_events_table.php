<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akıllı Arama — conversion (aramadan ciro) attribution alanları.
 * type='conversion' event'inde sipariş tutarı (value) + platform sipariş no tutulur.
 * "Buradan aratıp sipariş veren" cirosu bu satırlardan toplanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->decimal('value', 12, 2)->nullable()->after('position');
            $table->string('platform_order_id', 64)->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->dropColumn(['value', 'platform_order_id']);
        });
    }
};

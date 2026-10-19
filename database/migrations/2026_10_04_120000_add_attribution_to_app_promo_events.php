<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web→uygulama geçişinde reklam kaynağını kaybetmemek için: ziyaretçinin siteye geldiği
 * son kampanya dokunuşu (widget localStorage'da 7 gün tutar) her olaya yazılır.
 * channel: meta | google | other | null (doğrudan/organik)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_promo_events', function (Blueprint $table) {
            $table->string('channel', 16)->nullable();
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_medium', 64)->nullable();
            $table->string('utm_campaign', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_promo_events', function (Blueprint $table) {
            $table->dropColumn(['channel', 'utm_source', 'utm_medium', 'utm_campaign']);
        });
    }
};

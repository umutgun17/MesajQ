<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ziyaretçinin sohbet penceresini AÇIK tuttuğu son an.
 *
 * `last_activity_at` bu iş için kullanılamıyordu: personelin cevabı da onu
 * tazeliyor, dolayısıyla cevabın hemen ardından ziyaretçi "sayfada" görünüyordu.
 * Bu alanı yalnız widget'ın yoklaması ve müşterinin kendi mesajı günceller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};

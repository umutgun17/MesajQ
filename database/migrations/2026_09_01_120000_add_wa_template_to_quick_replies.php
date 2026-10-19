<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hazır cevapların WhatsApp şablonu karşılığı.
 *
 * 24 saatlik pencere kapandığında temsilci hazır cevabı düz mesaj olarak
 * gönderemiyor; aynı metnin Meta onaylı bir şablon karşılığı olması gerekiyor.
 * Şablon WABA'ya bağlı olduğu için eşleme tenant bazında tutulur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_replies', function (Blueprint $table) {
            $table->string('wa_template_name', 190)->nullable()->index();
            $table->string('wa_template_status', 20)->nullable();   // PENDING/APPROVED/REJECTED
            $table->string('wa_template_category', 20)->nullable(); // UTILITY/MARKETING
            $table->timestamp('wa_template_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quick_replies', function (Blueprint $table) {
            $table->dropColumn(['wa_template_name', 'wa_template_status', 'wa_template_category', 'wa_template_synced_at']);
        });
    }
};

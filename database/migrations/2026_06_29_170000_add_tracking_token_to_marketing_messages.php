<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * marketing_messages.tracking_token — e-posta açılma/tıklama takibi için benzersiz,
 * tahmin edilemez anahtar. Açılış pikseli ve tıklama yönlendirmesi bu token'la
 * mesajı bulup opened_at/clicked_at işaretler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_messages', function (Blueprint $table) {
            $table->string('tracking_token', 64)->nullable()->after('provider_message_id');
            $table->index('tracking_token');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_messages', function (Blueprint $table) {
            $table->dropIndex(['tracking_token']);
            $table->dropColumn('tracking_token');
        });
    }
};

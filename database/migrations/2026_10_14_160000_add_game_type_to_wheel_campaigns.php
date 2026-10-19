<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Oyun Kampanyaları: her kampanya tek bir oyun türüdür — wheel (çark) | scratch (kazı kazan).
        // Çark kampanyası settings.ab_partner_campaign_id ile bir kazı kazan kampanyasını A/B eşi
        // olarak gösterebilir (tek gömme kodu, ziyaretçi yüzdeyle birine düşer).
        Schema::table('wheel_campaigns', function (Blueprint $table) {
            $table->string('game_type', 16)->default('wheel')->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('wheel_campaigns', function (Blueprint $table) {
            $table->dropColumn('game_type');
        });
    }
};

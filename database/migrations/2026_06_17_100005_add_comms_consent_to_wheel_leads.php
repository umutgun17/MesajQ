<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wheel_leads', function (Blueprint $table) {
            // Ticari elektronik ileti (SMS/e-posta/WhatsApp) izni — KVKK açık rızadan AYRI.
            $table->boolean('comms_consent')->default(false)->after('kvkk_consent_at');
            $table->text('comms_consent_text')->nullable()->after('comms_consent');
            $table->timestamp('comms_consent_at')->nullable()->after('comms_consent_text');
        });
    }

    public function down(): void
    {
        Schema::table('wheel_leads', function (Blueprint $table) {
            $table->dropColumn(['comms_consent', 'comms_consent_text', 'comms_consent_at']);
        });
    }
};

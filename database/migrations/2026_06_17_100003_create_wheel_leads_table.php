<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wheel_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wheel_campaign_id')->constrained()->cascadeOnDelete();

            // Toplanan iletişim bilgileri (kampanya form ayarına göre kısmen dolu olabilir)
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // KVKK açık rıza kaydı — kanıt için zaman + IP + metin versiyonu dondurulur
            $table->boolean('kvkk_consent')->default(false);
            $table->string('kvkk_consent_version')->nullable();
            $table->text('kvkk_consent_text')->nullable();
            $table->timestamp('kvkk_consent_at')->nullable();

            // Köken / hile takibi
            $table->string('source_url')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('device_fingerprint', 128)->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'wheel_campaign_id']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_leads');
    }
};

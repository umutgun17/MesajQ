<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kanal-bağımsız gönderim bastırma listesi (şikayet / "numaramı silin" / yanlış numara).
 * EmailSuppression yalnız e-posta unsubscribe'ı kapsar; bu tablo telefon dahil TÜM
 * kanalları kapsar ve UnifiedSender'da skip_gates'ten BAĞIMSIZ (transactional dahil)
 * kontrol edilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_suppressions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // 'all' = tüm kanallar; veya 'sms'|'whatsapp'|'email'|'push'
            $table->string('channel', 20)->default('all');
            // Normalize kimlik: e-posta lower(trim), telefon son-10-hane
            $table->string('identifier', 190);
            $table->string('reason', 500)->nullable();
            $table->string('source', 100)->nullable(); // complaint|manual|bounce...
            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'identifier']);
            $table->index(['tenant_id', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_suppressions');
    }
};

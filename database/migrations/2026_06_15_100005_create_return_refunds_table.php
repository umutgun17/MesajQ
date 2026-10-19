<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ödeme iadesi / kupon üretimi denemeleri.
     * idempotency_key UNIQUE — çift tıklama / retry'da çift para iadesini önler.
     */
    public function up(): void
    {
        Schema::create('return_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();

            $table->string('provider')->comment('iyzico | paytr | store_credit');
            $table->string('idempotency_key')->comment('Aynı iade için tekil anahtar — çift iade guard');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('TRY');

            $table->string('status')->default('pending')->comment('pending | processing | succeeded | failed');
            $table->string('provider_txn_id')->nullable()->comment('Sağlayıcı işlem/iade referansı');
            $table->string('coupon_code')->nullable()->comment('store_credit için üretilen kupon kodu');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();

            // Hassas tam veri TUTULMAZ — yalnızca maskelenmiş/özet payload
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index('return_request_id');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_refunds');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Siparişin geldiği e-ticaret kanalı (Ticimax)');

            // Talep tipi & takip
            $table->string('type')->comment('cancel (iptal) | return (iade)');
            $table->string('public_code')->comment('Müşteriye verilen benzersiz takip kodu');
            $table->string('status')->default('pending')->comment('Durum makinesi — bkz App\\Enums\\ReturnStatus');

            // Sipariş anlık görüntüsü (Ticimax canlı sorgudan — kanıt/denetim için dondurulur)
            $table->string('platform_order_id')->nullable()->comment('Ticimax SiparisID');
            $table->string('order_no')->nullable()->comment('Ticimax SiparisNo');
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('currency', 8)->default('TRY');

            // İade yöntemi & tutarlar (TÜMÜ sunucuda yeniden hesaplanır — client'a güvenilmez)
            $table->string('refund_method')->nullable()->comment('original (karta) | store_credit (kupon)');
            $table->decimal('requested_amount', 12, 2)->default(0)->comment('Müşteri talebi anındaki hesaplanan iade tutarı');
            $table->decimal('approved_amount', 12, 2)->nullable()->comment('Onay sonrası nihai iade tutarı');
            $table->decimal('refunded_amount', 12, 2)->default(0)->comment('Gerçekleşen toplam iade');
            $table->decimal('paid_amount_snapshot', 12, 2)->nullable()->comment('Sipariş ödenen tutar (tavan kontrolü için)');

            // Kurallar anlık görüntüsü (talep anında geçerli olan uygunluk kuralları)
            $table->jsonb('eligibility_snapshot')->nullable();

            // Çift onay (görev ayrılığı)
            $table->foreignId('staff_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('staff_approved_at')->nullable();
            $table->foreignId('manager_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manager_approved_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();

            // Kargo (Sürat — Faz 2)
            $table->string('cargo_provider')->nullable();
            $table->string('cargo_tracking_code')->nullable()->comment('Müşteriye verilen iade kargo kodu');
            $table->string('cargo_label_url')->nullable();
            $table->string('shipment_status')->nullable();
            $table->timestamp('received_at')->nullable()->comment('Kargo bize ulaştı (48s timer başlangıcı)');

            // ERP iptal (Nebim V3 — Faz 5)
            $table->jsonb('nebim_result')->nullable();

            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            $table->unique('public_code');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'platform_order_id']);
            $table->index(['tenant_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_requests');
    }
};

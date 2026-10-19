<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * marketing_journeys — bir müşterinin/siparişin çok adımlı bir otomasyona kaydı.
 *
 * İlk otomasyon: 'cart_recovery' (sepet kurtarma). Her terk edilmiş sipariş için
 * bir journey satırı açılır; adımlar (hatırlatma e-postaları) next_action_at ile
 * zamanlanır. Sipariş ödenirse status=converted + revenue attribute edilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_journeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Otomasyon anahtarı: cart_recovery | win_back | ...
            $table->string('automation', 40);

            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Gönderim için anlık görüntü (sipariş silinse de elde kalsın)
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone', 30)->nullable();

            // active | converted | stopped | exhausted
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('current_step')->default(0); // gönderilen adım sayısı
            $table->string('stopped_reason', 40)->nullable();         // paid|opted_out|expired|manual

            $table->timestamp('entered_at')->useCurrent();
            $table->timestamp('next_action_at')->nullable();          // bir sonraki adım zamanı
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('converted_at')->nullable();

            $table->decimal('revenue', 12, 2)->default(0);            // dönüşüm cirosu (attribution)

            $table->jsonb('meta')->nullable();
            $table->timestamps();

            // Aynı sipariş aynı otomasyona iki kez girmesin
            $table->unique(['tenant_id', 'automation', 'order_id']);
            // İşlenecek journey'leri hızlı bulmak için
            $table->index(['tenant_id', 'automation', 'status', 'next_action_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_journeys');
    }
};

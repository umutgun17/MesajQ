<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Ziyaretçi giriş yapmışsa müşteriye bağlanır (yoksa anonim cihaz)
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Tarayıcı push aboneliği (PushSubscription)
            $table->text('endpoint');
            $table->string('p256dh');   // public key
            $table->string('auth');     // auth secret
            $table->string('encoding', 20)->default('aesgcm');

            // Köken / takip
            $table->string('origin')->nullable();        // hangi site (alan adı)
            $table->string('user_agent', 512)->nullable();
            $table->string('visitor_ref')->nullable();    // siteden gelen anonim/üye referansı
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            // Aynı endpoint tekrar abone olursa güncellenir
            $table->unique(['tenant_id', 'endpoint'], 'wps_tenant_endpoint_unique');
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
    }
};

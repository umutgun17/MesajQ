<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * marketing_email_templates — panelden düzenlenebilen HTML e-posta şablonları.
 *
 * Her tenant kendi şablonlarına sahip. `key` strateji/otomasyon anahtarı
 * (welcome, cart_recovery_1, order_confirmation ...). `html` gönderilecek son
 * HTML; `design` GrapesJS proje JSON'u (tekrar düzenlenebilsin diye).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('key', 60);                 // welcome | cart_recovery_1 | ...
            $table->string('name');                    // panelde görünen ad
            $table->string('category', 40)->default('general'); // strateji grubu
            $table->string('subject');                 // e-posta konusu (değişken içerebilir)
            $table->string('preheader')->nullable();   // önizleme metni

            $table->longText('html')->nullable();      // gönderilecek HTML
            $table->jsonb('design')->nullable();       // GrapesJS proje verisi (yeniden düzenleme)

            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false); // seed ile gelen varsayılan mı

            // Şablonun desteklediği değişkenler (panelde ipucu için) ["customer_name","cart_total",...]
            $table->jsonb('variables')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_email_templates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beden Seçimi (CRO) olayları: sayfa görüntüleme, seçim paneli açılışı, numara seçimi, sepete ekleme,
 * stoksuz numara, gelince haber ver, benzer ürün / renk tıklaması, kalıp tavsiyesi gösterimi.
 * bucket: A = mobil seçim paneli açık, B = kapalı (A/B testi); A/B kapalıyken hep A.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('session_id', 64)->nullable();
            $table->char('bucket', 1)->default('A');
            $table->string('type', 24);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('value', 120)->nullable();
            $table->char('device', 1)->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_events');
    }
};

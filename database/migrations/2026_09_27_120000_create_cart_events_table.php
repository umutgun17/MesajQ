<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sepet Kampanyası (CRO) olayları: view/update (çubuk çizildi/sepet değişti; basis=sepet tutarı, tier=kazanılan kademe sayısı,
 * remaining=sıradaki kademeye kalan) | unlock (yeni kademe kazanıldı) | cta (alışverişe devam) | checkout | error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('session_id', 64)->nullable();
            $table->string('surface', 12)->default('side');   // side | checkout
            $table->string('type', 16);
            $table->decimal('basis', 12, 2)->default(0);
            $table->unsignedSmallInteger('tier')->default(0);
            $table->decimal('remaining', 12, 2)->default(0);
            $table->unsignedSmallInteger('items')->default(0);
            $table->string('value', 160)->nullable();
            $table->string('page_path', 200)->nullable();
            $table->string('device', 8)->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_events');
    }
};

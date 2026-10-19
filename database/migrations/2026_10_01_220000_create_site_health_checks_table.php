<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mağaza sağlık kontrolü ölçümleri (site:health-check). kind=probe (dışarıdan yoklama: sepet API'si, Ticimax servis,
 * ödeme kuruluşu; value=ms) | funnel (gerçek müşteri hunisi: sepet → ödeme sayfası → sipariş denemesi → onaylı sipariş;
 * value=gerçekleşen, expected=günün gidişine göre beklenen). status: ok | warn | fail | blocked | skip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_health_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->timestamp('checked_at');
            $table->string('kind', 8);
            $table->string('key', 40);
            $table->string('status', 8);
            $table->decimal('value', 12, 2)->nullable();
            $table->decimal('expected', 12, 2)->nullable();
            $table->string('note', 255)->nullable();
            $table->boolean('alerted')->default(false);

            $table->index(['tenant_id', 'checked_at']);
            $table->index(['tenant_id', 'key', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_health_checks');
    }
};

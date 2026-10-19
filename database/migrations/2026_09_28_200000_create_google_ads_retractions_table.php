<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toptan/kurum içi siparişlerin Google Ads dönüşümlerinden geri çekilmesi (RETRACTION) izi.
 * status: pending (Google'da henüz bulunamadı, tekrar denenir) | retracted | not_found (süre doldu) | error | validated
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_retractions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('order_id');
            $table->string('order_no', 64);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('reason', 32)->default('wholesale_threshold');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('conversion_action', 96)->nullable();
            $table->text('last_response')->nullable();
            $table->timestamp('retracted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_retractions');
    }
};

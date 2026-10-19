<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM › Gecikmeler: geciken siparişlere personel müdahalesinin kaydı.
 * order_delay_reviews = siparişin güncel inceleme durumu (sipariş başına tek satır)
 * order_delay_events  = durum değişikliği / not / Ticimax'tan yenileme geçmişi
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_delay_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('state', 24)->default('new');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->date('follow_up_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'state']);
        });

        Schema::create('order_delay_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 16);
            $table->string('from_state', 24)->nullable();
            $table->string('to_state', 24)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delay_events');
        Schema::dropIfExists('order_delay_reviews');
    }
};

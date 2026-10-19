<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strateji çalışmaları: tek strateji (ör. Kampanya Fabrikası) altında birden
 * çok bağımsız uygulama — her kategori çalışması kendi config + adım akışını
 * taşır (üretilen kampanya ID'si, kreatif galerisi, denetim ve karne dahil).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_strategy_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('strategy_id')->constrained('meta_strategies')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('status', 20)->default('active'); // active | completed | archived
            $table->jsonb('config')->nullable();
            $table->jsonb('steps')->nullable();
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_strategy_runs');
    }
};

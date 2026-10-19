<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Akıllı Menü (CRO) olayları: open | tab | tile | virtual | product | recent | search | close. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('session_id', 64)->nullable();
            $table->string('type', 16);
            $table->string('segment', 60)->nullable();    // aktif sekme (Kadın/Erkek…)
            $table->string('value', 200)->nullable();     // kategori/sanal/ürün url
            $table->unsignedSmallInteger('position')->nullable(); // kartın sırası (1..)
            $table->string('source', 24)->nullable();     // open: hamburger|bottombar|api · tile: sıra kaynağı sales|catalog|personal|season|pinned
            $table->string('page_path', 200)->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_events');
    }
};

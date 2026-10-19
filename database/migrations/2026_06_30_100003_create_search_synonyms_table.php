<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akıllı Arama — eşanlamlı / yazım düzeltme / yönlendirme kuralları.
 * source=manual (panelden) veya source=auto (agentic döngü önerisi). status=suggested
 * olanlar merchant onayına düşer; auto_apply_synonyms kapalıyken otomatik uygulanmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('term', 120);                       // kaynak terim
            $table->jsonb('synonyms');                          // ["eşanlamlı1","eşanlamlı2"]
            $table->string('type', 20)->default('synonym');    // synonym|spelling|redirect
            $table->string('source', 20)->default('manual');   // manual|auto
            $table->string('status', 20)->default('active');   // active|suggested|rejected
            $table->unsignedInteger('hits')->default(0);       // kaç sorguda devreye girdi
            $table->jsonb('meta')->nullable();                 // agentic gerekçe / örnek sorgular
            $table->timestamps();

            $table->unique(['tenant_id', 'term', 'type']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_synonyms');
    }
};

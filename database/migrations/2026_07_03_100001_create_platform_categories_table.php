<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori Denetçisi — Ticimax kategori ağacının yerel aynası (SelectKategori).
 * is_assignable=false: kampanya/vitrin kategorileri (TEK FİYAT, İNDİRİM vb.) —
 * sınıflandırıcı bunlara ürün ATAMAZ ama üründe varsa korur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedInteger('platform_category_id');   // Ticimax Kategori.ID
            $table->unsignedInteger('parent_platform_id')->default(0); // Kategori.PID (0=kök)
            $table->string('name', 190);                       // Kategori.Tanim
            $table->string('path', 500)->nullable();           // "Kadın > Kadın Ayakkabı > Babet"
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('url', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('children_count')->default(0);
            $table->unsignedInteger('product_count')->default(0); // atama aynasından
            $table->boolean('is_assignable')->default(true);
            $table->jsonb('meta')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform_category_id']);
            $table->index(['tenant_id', 'parent_platform_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_categories');
    }
};

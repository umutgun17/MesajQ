<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori Denetçisi — sınıflandırıcı önerileri (sinonim onay akışıyla aynı desen).
 * tier=auto: katmanlar hemfikir + güven yüksek → --live'da otomatik uygulanır.
 * tier=review: panelde/raporda onay bekler; onaylanınca catalog:apply-categories yazar.
 * action=create_category: ağaçta uygun yer yok, yeni kategori önerisi (HER ZAMAN onaylı).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('action', 30)->default('recategorize'); // recategorize|create_category
            $table->jsonb('current')->nullable();          // {primary_id, category_ids[], category, path}
            $table->unsignedInteger('suggested_primary_id')->nullable(); // Ticimax KategoriID
            $table->jsonb('suggested_category_ids')->nullable();          // ikincil yaprak id'leri
            $table->jsonb('new_category')->nullable();     // {name, parent_platform_id, parent_path}
            $table->decimal('confidence', 4, 3)->default(0);
            $table->string('tier', 20)->default('review'); // auto|review
            $table->jsonb('sources')->nullable();          // katman oyları: family/rules/knn/llm
            $table->text('rationale')->nullable();
            $table->string('status', 20)->default('suggested'); // suggested|approved|rejected|auto_applied|applied|failed
            $table->text('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_suggestions');
    }
};

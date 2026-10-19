<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Aksiyon Planı — kategori SEO motoru (tenant bağımsız, danışman raporu gerektirmez).
 *
 * seo_category_fields  : Ticimax'taki kategori SEO alanlarının yerel aynası (SelectKategori → title/description/
 *                        keywords/içerik + içerikten türetilen H1). Analiz ve yazma öncesi/sonrası doğrulama buradan.
 * seo_recommendations  : kural motorunun (veya içe aktarılan danışman listesinin) ürettiği alan bazlı öneriler:
 *                        mevcut → önerilen, kural, gerekçe, onay durumu, uygulanma sonucu.
 * seo_apply_batches    : Ticimax'a yazma turları (dry-run/canlı) — yazma ÖNCESİ alan yedeği (snapshot) ve sonuç;
 *                        geri alma snapshot'tan yapılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_category_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('platform_category_id');
            $table->unsignedBigInteger('parent_platform_id')->nullable();
            $table->string('name', 255);
            $table->string('full_name', 255)->nullable();       // yol bilgisinden türetilen tam ad ("Kadın Bot")
            $table->string('family', 24)->nullable();           // shoes|apparel|bags|accessories|campaign|generic
            $table->string('url', 512)->nullable();
            $table->string('path', 1024)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('product_count')->default(0);
            $table->string('seo_title', 1024)->nullable();
            $table->text('seo_description')->nullable();
            $table->text('seo_keywords')->nullable();
            $table->longText('content')->nullable();
            $table->boolean('has_h1')->default(false);
            $table->string('h1_text', 512)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform_category_id']);
        });

        Schema::create('seo_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('platform_category_id')->nullable();
            $table->string('url', 512)->nullable();
            $table->string('field', 16);                        // title|description|keywords|content|structure
            $table->string('rule', 40);                         // title_missing, description_stale_year, h1_missing, …
            $table->string('reason', 512)->nullable();
            $table->text('current')->nullable();
            $table->text('suggested')->nullable();
            $table->string('status', 16)->default('pending');   // pending|approved|rejected|applied|failed
            $table->string('source', 16)->default('rule');      // rule|import|manual
            $table->boolean('applicable')->default(true);       // false = yalnız bilgi (yapısal karar), Ticimax'a yazılmaz
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'platform_category_id', 'field']);
        });

        Schema::create('seo_apply_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('mode', 8);                          // dry|live|rollback
            $table->string('status', 16)->default('done');      // done|failed|rolled_back
            $table->unsignedInteger('count_total')->default(0);
            $table->unsignedInteger('count_ok')->default(0);
            $table->unsignedInteger('count_failed')->default(0);
            $table->json('snapshot')->nullable();               // {platform_category_id: {seo_title, seo_description, seo_keywords, content}}
            $table->json('result')->nullable();                 // [{category_id, url, fields:[…], ok, error}]
            $table->unsignedBigInteger('rolled_back_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_apply_batches');
        Schema::dropIfExists('seo_recommendations');
        Schema::dropIfExists('seo_category_fields');
    }
};

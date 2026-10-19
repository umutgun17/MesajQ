<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO & GEO ürünü — 10. alt ürün (`seo`).
 *
 * seo_scans : bir mağaza taraması (koşu). Site geneli kontroller (robots/sitemap/llms.txt/
 *             AI bot erişimi/güvenlik başlıkları) + üç sütun puan (seo/geo/perf) + özet sayaçlar.
 *             Tarama arka planda koşar (status: queued|running|done|failed), panel yoklar.
 * seo_pages : taramadaki her sayfa — çıkarılan alanlar (title/description/canonical/H1/JSON-LD…),
 *             sayfa tipi, hız ölçümleri ve tek tek kontroller [{key,label,status,detail,advice,weight,group,pillar}].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_scans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('status', 16)->default('queued');   // queued|running|done|failed
            $table->string('base_url', 255);
            $table->unsignedSmallInteger('pages_planned')->default(0);
            $table->unsignedSmallInteger('pages_done')->default(0);
            $table->unsignedTinyInteger('score')->default(0);      // genel 0-100
            $table->unsignedTinyInteger('score_seo')->default(0);
            $table->unsignedTinyInteger('score_geo')->default(0);
            $table->unsignedTinyInteger('score_perf')->default(0);
            $table->json('site_checks')->nullable();               // site geneli kontroller
            $table->json('summary')->nullable();                   // sayaçlar: issues by severity, top issues, page-type scores
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('scan_id')->index();
            $table->string('page_type', 16);                       // home|category|product|static|other
            $table->text('url');
            $table->text('final_url')->nullable();
            $table->unsignedSmallInteger('http_status')->default(0);
            $table->unsignedTinyInteger('redirects')->default(0);
            $table->unsignedInteger('ttfb_ms')->default(0);
            $table->unsignedInteger('total_ms')->default(0);
            $table->unsignedInteger('html_bytes')->default(0);
            $table->string('title', 512)->nullable();
            $table->string('meta_description', 1024)->nullable();
            $table->string('canonical', 1024)->nullable();
            $table->string('robots_meta', 64)->nullable();
            $table->string('h1', 512)->nullable();
            $table->unsignedSmallInteger('h1_count')->default(0);
            $table->unsignedSmallInteger('h2_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedSmallInteger('images')->default(0);
            $table->unsignedSmallInteger('images_no_alt')->default(0);
            $table->unsignedSmallInteger('internal_links')->default(0);
            $table->unsignedSmallInteger('external_links')->default(0);
            $table->json('schema_types')->nullable();              // ["Product","Offer",...]
            $table->json('extracted')->nullable();                 // og, hreflang, lang, product schema fields, headings...
            $table->unsignedTinyInteger('score')->default(0);
            $table->unsignedTinyInteger('score_seo')->default(0);
            $table->unsignedTinyInteger('score_geo')->default(0);
            $table->unsignedTinyInteger('score_perf')->default(0);
            $table->json('checks');
            $table->unsignedSmallInteger('fails')->default(0);
            $table->unsignedSmallInteger('warns')->default(0);
            $table->timestamps();

            $table->index(['scan_id', 'page_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_pages');
        Schema::dropIfExists('seo_scans');
    }
};

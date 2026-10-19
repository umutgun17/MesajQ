<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kreatif Stüdyo: ürün setleri + görsel/video şablonları + enhanced katalog
// (set→şablon eşleşmesi, feed'e geri verilen görseller) + video üretimleri.
return new class extends Migration
{
    public function up(): void
    {
        // Ürün setleri: kampanya/şablon hedeflemek için elle yönetilen ürün grupları.
        Schema::create('product_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('product_set_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['product_set_id', 'product_id']);
        });

        // Şablonlar: tenant_id NULL = yerleşik (tüm tenant'lara açık).
        // type: image (katalog görseli) | video (koleksiyon videosu).
        // config: canvas + eleman listesi (badge/bar/ribbon/frame/logo/text) veya video parametreleri.
        Schema::create('creative_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20); // image | video
            $table->string('key', 60);
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('config')->default('{}');
            $table->unsignedSmallInteger('min_products')->default(1);
            $table->unsignedSmallInteger('max_products')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['type', 'key', 'tenant_id']);
        });

        // Set → şablon eşleşmesi. Aktifken feed'deki image_link bizim render'la değişir.
        Schema::create('enhanced_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('product_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('creative_templates')->cascadeOnDelete();
            // draft → processing → active | failed; disabled = feed'den geri çekildi.
            $table->string('status', 20)->default('draft');
            $table->jsonb('settings')->default('{}'); // metin/renk/logo override'ları
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('rendered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('last_rendered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Ürün başına render edilmiş görsel (public/creative altında dosya + public URL).
        Schema::create('enhanced_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enhanced_catalog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('source_image_url', 1024)->nullable();
            $table->string('path', 512)->nullable();       // public/ altına göre göreli yol
            $table->string('public_url', 1024)->nullable(); // feed'e yazılacak mutlak URL
            $table->string('status', 20)->default('pending'); // pending|completed|failed
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['enhanced_catalog_id', 'product_id']);
            $table->index(['tenant_id', 'status']);
        });

        // Video üretimleri: kind=collection (ffmpeg şablon) | ai (fal.ai image-to-video).
        Schema::create('creative_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // collection | ai
            $table->string('name');
            $table->foreignId('template_id')->nullable()->constrained('creative_templates')->nullOnDelete();
            $table->jsonb('params')->default('{}'); // product_ids, metinler, renkler, prompt, model...
            $table->string('status', 20)->default('pending'); // pending|processing|completed|failed
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('output_path', 512)->nullable();
            $table->string('public_url', 1024)->nullable();
            $table->string('thumb_url', 1024)->nullable();
            $table->unsignedSmallInteger('duration_sec')->nullable();
            $table->jsonb('provider')->default('{}'); // fal request id, model, maliyet
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_videos');
        Schema::dropIfExists('enhanced_images');
        Schema::dropIfExists('enhanced_catalogs');
        Schema::dropIfExists('creative_templates');
        Schema::dropIfExists('product_set_items');
        Schema::dropIfExists('product_sets');
    }
};

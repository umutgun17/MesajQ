<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kreatif İstihbarat: Meta Ad Library'den anahtar kelimeyle "tutan" reklamları
 * (uzun süredir aktif = kanıtlanmış kreatif) çekip saklar. Veri Apify aktörüyle
 * gelir; her arama bir snapshot'tır, refresh eski satırları silip yeniden yazar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_intel_searches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('keyword', 200);
            $table->string('country', 5)->default('TR');
            $table->string('status', 20)->default('pending'); // pending | running | completed | failed
            $table->jsonb('params')->nullable();  // min_days, count, media_type, exclude_dpa
            $table->jsonb('stats')->nullable();   // fetched, kept, filtered, pages, cost_usd, total_available
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('ad_intel_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_id')->constrained('ad_intel_searches')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('ad_archive_id', 40);
            $table->string('page_id', 40);
            $table->string('page_name', 255)->nullable();
            $table->text('page_profile_pic')->nullable();
            $table->unsignedBigInteger('page_like_count')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('days_active')->default(0)->index();
            $table->string('collation_id', 60)->nullable();
            $table->unsignedInteger('collation_count')->default(1);
            $table->string('display_format', 30)->nullable(); // VIDEO | IMAGE | DPA | DCO | CAROUSEL...
            $table->string('media_type', 15)->default('other'); // video | image | carousel | other
            $table->text('body_text')->nullable();
            $table->string('title', 500)->nullable();
            $table->string('cta_text', 100)->nullable();
            $table->string('cta_type', 50)->nullable();
            $table->text('link_url')->nullable();
            $table->text('thumb_url')->nullable();
            $table->text('media_url')->nullable();
            $table->text('ad_library_url')->nullable();
            $table->jsonb('publisher_platforms')->nullable();
            $table->string('group_key', 80)->index(); // collation_id ya da page+kreatif hash — versiyon gruplama
            $table->decimal('score', 6, 2)->default(0)->index();
            $table->timestamps();

            $table->unique(['search_id', 'ad_archive_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_intel_ads');
        Schema::dropIfExists('ad_intel_searches');
    }
};

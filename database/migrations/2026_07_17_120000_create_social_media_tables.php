<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Otomatik Sosyal Medya Yönetimi (Instagram + Facebook organik):
 *  - social_plans:  haftalık/15 günlük/aylık paylaşım planları
 *  - social_posts:  plana bağlı tekil paylaşımlar (post/reels/story) + onay + yayın sonucu
 *  - social_assets: tenant Drive klasöründen içe alınan görsel havuzu (AI puanlı)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title');
            $table->string('period', 20)->default('month'); // week | fortnight | month
            $table->date('starts_at');
            $table->date('ends_at');
            // draft|generating|pending_approval|active|completed|cancelled
            $table->string('status', 30)->default('draft')->index();
            $table->jsonb('config')->default('{}'); // hedef sayılar, approval_mode anlık görüntüsü, notlar
            $table->jsonb('stats')->default('{}');
            $table->timestamps();
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('plan_id')->nullable()->constrained('social_plans')->cascadeOnDelete();
            $table->string('type', 20); // post | reel | story
            $table->jsonb('channels')->default('["instagram"]'); // instagram, facebook
            $table->timestampTz('scheduled_at')->nullable();
            // draft|generating|ready|pending_approval|approved|rejected|queued|publishing|published|failed|skipped
            $table->string('status', 30)->default('draft');
            $table->text('caption')->nullable();
            $table->jsonb('media')->default('[]');   // [{kind,url,path,thumb_url,width,height}]
            $table->jsonb('source')->default('{}');  // {asset_id, excluded:[], ai:{provider,model,prompt}, product:{id,name,url}}
            $table->jsonb('publish_results')->default('{}'); // {instagram:{id,permalink}|{error}, facebook:{...}}
            $table->text('error')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'scheduled_at']);
        });

        Schema::create('social_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('source', 20)->default('drive'); // drive | ai | upload
            $table->string('drive_file_id')->nullable();
            $table->string('folder')->nullable(); // Drive alt klasörü (kategori ipucu)
            $table->string('name');
            $table->string('mime', 100)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('local_path')->nullable();
            $table->string('public_url')->nullable();
            $table->string('thumb_url')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // new|downloading|analyzing|ready|rejected|error
            $table->string('status', 30)->default('new')->index();
            $table->decimal('score', 4, 2)->nullable(); // AI kalite puanı 0-10
            $table->jsonb('analysis')->default('{}');   // {tags,season,category,quality_notes,product_guess}
            $table->unsignedInteger('used_count')->default(0);
            $table->timestampTz('last_used_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'drive_file_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_plans');
        Schema::dropIfExists('social_assets');
    }
};

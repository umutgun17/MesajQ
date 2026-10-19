<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok Sosyal Medya (trend radarı + AI içerik üretimi; yayın MANUEL):
 *  - tiktok_scan_videos: günlük TR taramasında toplanan ham videolar (görüntülenme
 *    geçmişiyle — hız/ivme hesabı ardışık taramalardaki delta'dan çıkar)
 *  - tiktok_trends:      taramadan damıtılan trend kartları (ses/hashtag/format/tema)
 *                        + tenant marka-uyum skoru + uyarlama fikri
 *  - tiktok_contents:    üretilen videolar (galeri + indir + manuel paylaşım notları)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_scan_videos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('video_id', 64);
            $table->string('author')->nullable();
            $table->unsignedBigInteger('author_followers')->nullable();
            $table->text('text')->nullable();
            $table->jsonb('hashtags')->default('[]');   // ["kesfet","ayakkabi",...]
            $table->jsonb('music')->default('{}');      // {id,name,author,original}
            $table->jsonb('stats')->default('{}');      // son ölçüm {plays,likes,comments,shares,saves}
            $table->jsonb('history')->default('[]');    // [{at,plays,likes}] — büyüme delta'ları
            $table->string('cover_url', 1000)->nullable();
            $table->string('video_url', 1000)->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->string('query')->nullable();        // hangi arama/hashtag'ten geldi
            $table->timestampTz('scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'video_id']);
            $table->index(['tenant_id', 'scanned_at']);
        });

        Schema::create('tiktok_trends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('kind', 20); // sound | hashtag | format | theme
            $table->string('title', 300);
            $table->text('summary')->nullable();
            // new|shortlisted|dismissed|produced|expired
            $table->string('status', 30)->default('new')->index();
            $table->decimal('score', 4, 2)->nullable(); // tenant marka-uyum 0-10
            $table->text('rationale')->nullable();      // skorun gerekçesi
            $table->jsonb('idea')->default('{}');       // {hook,concept,scenes,on_screen_text,caption,hashtags,sound,realism_notes}
            $table->jsonb('metrics')->default('{}');    // {videos,total_plays,avg_engagement,velocity}
            $table->jsonb('examples')->default('[]');   // [{url,cover,author,plays,text}]
            $table->jsonb('source')->default('{}');     // {queries:[],video_ids:[]}
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'title']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('tiktok_contents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('trend_id')->nullable()->constrained('tiktok_trends')->nullOnDelete();
            $table->string('title', 300);
            // queued|generating|ready|failed|archived|posted
            $table->string('status', 30)->default('queued')->index();
            $table->jsonb('brief')->default('{}');      // Claude senaryo brief'i (hook, sahneler, üslup)
            $table->jsonb('media')->default('[]');      // [{kind,url,path,thumb_url}]
            $table->text('caption')->nullable();
            $table->jsonb('hashtags')->default('[]');
            $table->jsonb('post_notes')->default('{}'); // {sound,best_time,instructions} — manuel paylaşım rehberi
            $table->jsonb('source')->default('{}');     // {ai:{provider,image_prompt,video_prompt}}
            $table->text('error')->nullable();
            $table->timestampTz('posted_at')->nullable(); // kullanıcı "paylaştım" işaretler
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_contents');
        Schema::dropIfExists('tiktok_trends');
        Schema::dropIfExists('tiktok_scan_videos');
    }
};

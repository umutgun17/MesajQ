<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRO (Conversion Rate Optimization) ürünü — 9. alt ürün.
 *
 * cro_actions : tenant'ın dönüşüm aksiyon planı (fikir → planlı → yayında → ölçüldü).
 *               ICE puanı (etki × güven × kolaylık) ile önceliklenir; Yunbi'nin mevcut
 *               bir aracına (kargo sayacı, çark, öneri...) bağlanabilir.
 * cro_audits  : mağaza sayfalarının otomatik UX/CRO denetim sonuçları (sayfa tipi başına
 *               puan + tek tek kontroller). Geçmiş tutulur; panel son koşuyu gösterir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cro_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('key', 64)->nullable();          // katalogdan tohumlanan aksiyonlarda sabit anahtar (mükerrer tohum engeli)
            $table->string('title', 160);
            $table->string('category', 32)->default('other'); // pdp|category|cart|checkout|trust|speed|mobile|search|personalization|retention|other
            $table->string('surface', 80)->nullable();        // "Ürün sayfası", "Sepet", ...
            $table->text('hypothesis')->nullable();           // "X yaparsak Y olur çünkü Z"
            $table->text('description')->nullable();
            $table->string('status', 16)->default('idea');    // idea|planned|running|done|discarded
            $table->unsignedTinyInteger('impact')->default(3);     // 1-5
            $table->unsignedTinyInteger('confidence')->default(3); // 1-5
            $table->unsignedTinyInteger('ease')->default(3);       // 1-5
            $table->string('expected_uplift', 80)->nullable();     // "+%10-15 sepete ekleme"
            $table->string('feature', 48)->nullable();             // bağlı Yunbi aracı anahtarı
            $table->string('feature_url', 160)->nullable();        // panelde ilgili sayfa
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('result')->nullable();
            $table->decimal('result_uplift', 8, 2)->nullable();    // ölçülen % etki
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('cro_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('run_id', 36)->index();             // aynı koşudaki sayfaları gruplar
            $table->string('page_type', 16);                   // home|category|product|cart|other
            $table->text('url');
            $table->unsignedSmallInteger('http_status')->default(0);
            $table->unsignedInteger('ttfb_ms')->default(0);
            $table->unsignedInteger('total_ms')->default(0);
            $table->unsignedInteger('html_bytes')->default(0);
            $table->unsignedTinyInteger('score')->default(0);  // 0-100
            $table->json('checks');                            // [{key,label,status,detail,advice,weight}]
            $table->json('meta')->nullable();                  // title, script_count, img_count, widgets...
            $table->timestamps();

            $table->index(['tenant_id', 'page_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cro_audits');
        Schema::dropIfExists('cro_actions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Strateji Merkezi: her strateji adım listesiyle (JSON) yaşar;
        // adımlar analiz→onay→üretim→yayın→izleme akışında ilerler.
        Schema::create('meta_strategies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('key', 60); // dayparting_boost, city_creative, ...
            $table->string('name', 150);
            $table->string('category', 40); // zamanlama | kreatif | katalog | kitle | yapi
            $table->string('status', 20)->default('draft'); // draft | active | paused | completed | archived
            $table->text('summary'); // kart üstü tek paragraf
            $table->text('rationale'); // neden/kanıt + riskler (uzun)
            $table->json('config'); // stratejiye özel ayarlanabilir parametreler
            $table->json('steps'); // [{no,title,desc,type,executor,needs_approval,status,result,completed_at}]
            $table->unsignedInteger('current_step')->default(0); // 1-bazlı; 0 = başlamadı
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_strategies');
    }
};

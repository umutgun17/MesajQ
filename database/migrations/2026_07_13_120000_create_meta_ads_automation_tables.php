<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kural motoru önerileri — öneri→onay→uygula audit akışı
        Schema::create('meta_ad_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('ad_account_id', 50);
            $table->string('level', 20); // campaign | adset
            $table->string('object_id', 50);
            $table->string('object_name', 255);
            $table->string('rule', 50); // kill_roas | kill_cpa | scale_budget | frequency_fatigue | ctr_drop
            $table->string('severity', 20); // critical | warning | opportunity
            $table->string('title', 255);
            $table->text('reason');
            $table->string('action', 30); // pause | budget_change | none
            $table->json('action_params')->nullable(); // ör. {current_budget, new_budget}
            $table->json('metrics')->nullable(); // karar anındaki metrik anlık görüntüsü
            $table->string('status', 20)->default('pending')->index(); // pending | applied | dismissed | failed | expired
            // Aynı nesne+kural için günde tek öneri
            $table->string('dedupe_key', 120)->unique();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('result_message', 500)->nullable();
            $table->timestamps();
        });

        // A/B testleri — iki kampanyanın metrik kıyası (yerel kayıt)
        Schema::create('meta_ab_tests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('ad_account_id', 50);
            $table->string('name', 255);
            $table->text('hypothesis')->nullable(); // değiştirilen değişken / beklenti
            $table->string('campaign_a_id', 50);
            $table->string('campaign_a_name', 255);
            $table->string('campaign_b_id', 50);
            $table->string('campaign_b_name', 255);
            $table->string('status', 20)->default('running'); // running | completed
            $table->string('winner', 5)->nullable(); // a | b | tie
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_recommendations');
        Schema::dropIfExists('meta_ab_tests');
    }
};

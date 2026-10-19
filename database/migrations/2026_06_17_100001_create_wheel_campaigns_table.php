<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wheel_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->comment('Widget gömme anahtarı (tenant içinde benzersiz)');
            $table->string('status')->default('draft')->comment('bkz App\\Enums\\WheelCampaignStatus');

            // Görsel tasarım + tetikleme + form alanları (bkz config/wheel.php)
            $table->jsonb('design')->nullable();
            $table->jsonb('trigger_rules')->nullable();
            $table->jsonb('form_fields')->nullable();

            // Yayın penceresi
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Kupon ayarı (display | ticimax) + geçerlilik
            $table->string('coupon_provider')->default('display');
            $table->unsignedSmallInteger('coupon_valid_hours')->default(48);

            $table->jsonb('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_campaigns');
    }
};

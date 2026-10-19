<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wheel_slices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wheel_campaign_id')->constrained()->cascadeOnDelete();

            $table->string('label')->comment('Dilim üzerinde yazan metin');
            $table->string('prize_type')->default('discount_percent')->comment('bkz App\\Enums\\WheelPrizeType');
            $table->decimal('prize_value', 12, 2)->default(0)->comment('yuzde ya da TL (prize_type bazli)');

            // Kazanma OLASILIGI — sunucuda agirlikli secim icin. Cliente ASLA gonderilmez.
            $table->unsignedInteger('weight')->default(1);

            // Görsel
            $table->string('color')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Kupon şablonu (display modunda kod gövdesi; örn "CARK10")
            $table->string('coupon_code_template')->nullable();

            // Stok limiti — bu ödülden en fazla kaç adet dağıtılacak (null = sınırsız)
            $table->unsignedInteger('stock_limit')->nullable();
            $table->unsignedInteger('stock_used')->default(0);

            $table->jsonb('settings')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'wheel_campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_slices');
    }
};

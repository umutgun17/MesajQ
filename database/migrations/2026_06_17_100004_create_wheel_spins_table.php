<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wheel_spins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wheel_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wheel_lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wheel_slice_id')->nullable()->constrained()->nullOnDelete();

            // Kazanılan ödülün anlık görüntüsü (dilim sonradan değişse de kanıt sabit kalır)
            $table->string('prize_label')->nullable();
            $table->string('prize_type')->nullable();
            $table->decimal('prize_value', 12, 2)->default(0);
            $table->boolean('is_win')->default(false);

            // Kupon
            $table->string('coupon_code')->nullable();
            $table->string('coupon_provider')->default('display');
            $table->string('coupon_status')->default('none')->comment('bkz App\\Enums\\WheelCouponStatus');
            $table->timestamp('coupon_expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();

            // Hile takibi
            $table->string('ip_address', 64)->nullable();
            $table->string('device_fingerprint', 128)->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'wheel_campaign_id']);
            $table->index(['tenant_id', 'coupon_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_spins');
    }
};

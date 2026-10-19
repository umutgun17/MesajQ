<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kişi bazlı pazarlama profili — site+app siparişlerinden türetilir (segments:refresh).
        Schema::create('marketing_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('phone', 20)->nullable();     // normalize: 905XXXXXXXXX
            $table->string('email')->nullable();         // normalize: lowercase
            $table->string('name')->nullable();
            $table->boolean('is_member')->default(false); // en az 1 siparişi üyelikli
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('refunded_count')->default(0);
            $table->decimal('total_spent', 14, 2)->default(0);   // tam iade (13) hariç
            $table->decimal('max_order_amount', 12, 2)->default(0);
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->string('first_source', 32)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_member']);
            $table->index(['tenant_id', 'last_order_at']);
            $table->index(['tenant_id', 'total_spent']);
        });
        DB::statement('create unique index marketing_profiles_tenant_phone_uq on marketing_profiles (tenant_id, phone) where phone is not null');
        DB::statement('create unique index marketing_profiles_tenant_email_uq on marketing_profiles (tenant_id, email) where email is not null');

        // Kural tabanlı segment tanımları — üyelik dinamik, sayım her zaman canlı.
        Schema::create('marketing_segments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->jsonb('definition');          // SegmentService kural sözleşmesi
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_segments');
        Schema::dropIfExists('marketing_profiles');
    }
};

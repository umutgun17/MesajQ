<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iys_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('phone', 20)->nullable();      // normalize: 905XXXXXXXXX
            $table->string('email')->nullable();          // normalize: lowercase
            $table->string('name')->nullable();
            $table->timestamp('first_order_at')->nullable();
            $table->string('first_order_no', 64)->nullable();
            $table->string('order_source', 32)->nullable();
            // pending | sent | partial | failed
            $table->string('status', 16)->default('pending');
            // tip bazlı sonuçlar: {"MESAJ":{"ok":true,"code":"00","at":"..."},"ARAMA":...,"EPOSTA":...}
            $table->jsonb('types_result')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'first_order_at']);
            $table->index(['tenant_id', 'last_attempt_at']);
        });

        // Aynı numara/e-posta tenant başına tek kişi (partial unique — null'lar serbest)
        DB::statement('create unique index iys_contacts_tenant_phone_uq on iys_contacts (tenant_id, phone) where phone is not null');
        DB::statement('create unique index iys_contacts_tenant_email_uq on iys_contacts (tenant_id, email) where email is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('iys_contacts');
    }
};

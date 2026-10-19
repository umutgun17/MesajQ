<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only denetim günlüğü (audit log).
     * Bu tabloya YALNIZCA INSERT yapılır — update/delete yapılmaz.
     * Her aktör/tutar/zaman/durum geçişi burada kalıcı tutulur.
     */
    public function up(): void
    {
        Schema::create('return_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();

            $table->string('actor_type')->comment('customer | staff | manager | system');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Panel kullanıcısı (staff/manager); customer/system için null');
            $table->string('action')->comment('Örn: lookup, submit, staff_approve, manager_approve, reject, refund_initiated...');

            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->decimal('amount', 12, 2)->nullable()->comment('İşleme ait tutar (varsa)');

            $table->jsonb('payload')->nullable()->comment('Ek bağlam (asla hassas tam kart verisi tutma)');
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent')->nullable();

            // Yalnızca created_at — değiştirilemez kayıt
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'return_request_id']);
            $table->index(['tenant_id', 'action']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_request_events');
    }
};

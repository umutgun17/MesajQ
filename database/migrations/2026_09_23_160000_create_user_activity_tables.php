<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel kullanıcı aktivitesi (hafif tasarım):
 *  - user_logins: her başarılı giriş 1 satır (nadir yazım).
 *  - user_page_views: sayfa görüntülemesi; aynı kullanıcı+sayfa için 2 dk penceresinde
 *    tek satır (Redis kilidi), API isteği başına DEĞİL. 90 gün sonra otomatik silinir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_logins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 16)->default('login');        // login | impersonation
            $table->unsignedBigInteger('impersonator_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('user_page_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('page', 160);                           // SPA yolu, id'ler {id} ile normalize
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_page_views');
        Schema::dropIfExists('user_logins');
    }
};
